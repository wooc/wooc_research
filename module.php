<?php
// Classes and libraries for module system
//
// webtrees: Web based Family History software
// Copyright (C) 2015 £ukasz Wileñski.
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
namespace Wooc\WebtreesAddon\WoocResearchModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\Controller\AjaxController;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Functions\FunctionsDb;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Functions\FunctionsPrintLists;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\GedcomTag;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Theme;
use Rhumsaa\Uuid\Uuid;

class WoocResearchModule extends AbstractModule implements ModuleMenuInterface, ModuleBlockInterface, ModuleConfigInterface {

	public function __construct() {
		parent::__construct('wooc_research');
	}

	// Extend class Module
	public function getTitle() {
		if (WT_SCRIPT_NAME === 'index_edit.php') {
			return I18N::translate('Task lists');
		} else {
			return I18N::translate('Wooc Research');
		}
	}

	// Extend class Module
	public function getMenuTitle() {
		return I18N::translate('Task lists');
	}

	// Extend class Module
	public function getDescription() {
		return I18N::translate('A list of tasks and missing information that are linked to the family tree.');
	}

	// Implement Module_Menu
	public function defaultMenuOrder() {
		return 50;
	}

	// Extend class Module
	public function defaultAccessLevel() {
		return Auth::PRIV_NONE;
	}

	// Implement Module_Config
	public function getConfigLink() {
		return 'module.php?mod='.$this->getName().'&amp;mod_action=admin_config';
	}

	// Implement class Module_Block
	public function getBlock($block_id, $template = true, $cfg = array()) {
		global $ctype, $controller, $WT_TREE;

		$show_other      = $this->getBlockSetting($block_id, 'show_other', '1');
		$show_unassigned = $this->getBlockSetting($block_id, 'show_unassigned', '1');
		$show_future     = $this->getBlockSetting($block_id, 'show_future', '1');
		$block           = $this->getBlockSetting($block_id, 'block', '1');

		foreach (array('show_unassigned', 'show_other', 'show_future', 'block') as $name) {
			if (array_key_exists($name, $cfg)) {
				$$name = $cfg[$name];
			}
		}

		$id    = $this->getName() . $block_id;
		$class = $this->getName() . '_block';
		if ($ctype === 'gedcom' && Auth::isManager($WT_TREE) || $ctype === 'user' && Auth::check()) {
			$title = '<a class="icon-admin" title="' . I18N::translate('Configure') . '" href="block_edit.php?block_id=' . $block_id . '&amp;ged=' . $WT_TREE->getNameHtml() . '&amp;ctype=' . $ctype . '"></a>';
		} else {
			$title = '';
		}
		$title .= I18N::translate('Task lists');

		$table_id = Uuid::uuid4(); // create a unique ID

		$controller
			->addExternalJavascript(WT_JQUERY_DATATABLES_JS_URL)
			->addInlineJavascript('
			jQuery("#' . $table_id . '").dataTable({
				dom: \'t\',
				' . I18N::datatablesI18N() . ',
				autoWidth: false,
				paginate: false,
				lengthChange: false,
				filter: false,
				info: true,
				jQueryUI: true,
				columns: [
					/* 0-DATE */     { visible: false },
					/* 1-Date */     { dataSort: 0 },
					/* 1-Record */   null,
					/* 2-Username */ null,
					/* 3-Text */     null
				]
			});
			jQuery("#' . $table_id . '").css("visibility", "visible");
			jQuery(".loading-image").css("display", "none");
		');

		$content = '';
		$content .= '<div class="loading-image">&nbsp;</div>';
		$content .= '<table id="' . $table_id . '" style="visibility:hidden;">';
		$content .= '<thead><tr>';
		$content .= '<th>DATE</th>'; //hidden by datables code
		$content .= '<th>' . GedcomTag::getLabel('DATE') . '</th>';
		$content .= '<th>' . I18N::translate('Record') . '</th>';
		if ($show_unassigned || $show_other) {
			$content .= '<th>' . I18N::translate('Username') . '</th>';
		}
		$content .= '<th>' . GedcomTag::getLabel('TEXT') . '</th>';
		$content .= '</tr></thead><tbody>';

		$found  = false;
		$end_jd = $show_future ? 99999999 : WT_CLIENT_JD;
		foreach (FunctionsDb::getCalendarEvents(0, $end_jd, '_TODO', $WT_TREE) as $fact) {
			$record    = $fact->getParent();
			$user_name = $fact->getAttribute('_WT_USER');
			if ($user_name === Auth::user()->getUserName() || !$user_name && $show_unassigned || $user_name && $show_other) {
				$content .= '<tr>';
				//-- Event date (sortable)
				$content .= '<td>'; //hidden by datables code
				$content .= $fact->getDate()->julianDay();
				$content .= '</td>';
				$content .= '<td class="wrap">' . $fact->getDate()->display() . '</td>';
				$content .= '<td class="wrap"><a href="' . $record->getHtmlUrl() . '">' . $record->getFullName() . '</a></td>';
				if ($show_unassigned || $show_other) {
					$content .= '<td class="wrap">' . $user_name . '</td>';
				}
				$text = $fact->getValue();
				$content .= '<td class="wrap">' . $text . '</td>';
				$content .= '</tr>';
				$found = true;
			}
		}

		$content .= '</tbody></table>';
		if (!$found) {
			$content .= '<p>' . I18N::translate('There are no research tasks in this family tree.') . '</p>';
		}

		if ($template) {
			if ($block) {
				$class .= ' small_inner_block';
			}

			return Theme::theme()->formatBlock($id, $title, $class, $content);
		} else {
			return $content;
		}
	}

	// Implement class Module_Block
	public function loadAjax() {
		return false;
	}

	// Implement class Module_Block
	public function isUserBlock() {
		return true;
	}

	// Implement class Module_Block
	public function isGedcomBlock() {
		return false;
	}

	// Implement class Module_Block
	public function configureBlock($block_id) {
		if (Filter::postBool('save')) {
			set_block_setting($block_id, 'show_other',      Filter::postBool('show_other'));
			set_block_setting($block_id, 'show_unassigned', Filter::postBool('show_unassigned'));
			set_block_setting($block_id, 'show_future',     Filter::postBool('show_future'));
			set_block_setting($block_id, 'block',			Filter::postBool('block'));
			echo WT_JS_START, 'window.opener.location.href=window.opener.location.href;window.close();', WT_JS_END;
			exit;
		}

		require_once WT_ROOT.'includes/functions/functions_edit.php';

		$show_other=$this->getBlockSetting($block_id, 'show_other', true);
		echo '<tr><td class="descriptionbox wrap width33">';
		echo I18N::translate('Show research tasks that are assigned to other users');
		echo '</td><td class="optionbox">';
		echo edit_field_yes_no('show_other', $show_other);
		echo '</td></tr>';

		$show_unassigned=$this->getBlockSetting($block_id, 'show_unassigned', true);
		echo '<tr><td class="descriptionbox wrap width33">';
		echo I18N::translate('Show research tasks that are not assigned to any user');
		echo '</td><td class="optionbox">';
		echo edit_field_yes_no('show_unassigned', $show_unassigned);
		echo '</td></tr>';

		$show_future=$this->getBlockSetting($block_id, 'show_future', true);
		echo '<tr><td class="descriptionbox wrap width33">';
		echo I18N::translate('Show research tasks that have a date in the future');
		echo '</td><td class="optionbox">';
		echo edit_field_yes_no('show_future', $show_future);
		echo '</td></tr>';

		$block=$this->getBlockSetting($block_id, 'block', true);
		echo '<tr><td class="descriptionbox wrap width33">';
		echo /* I18N: label for a yes/no option */ I18N::translate('Add a scrollbar when block contents grow');
		echo '</td><td class="optionbox">';
		echo edit_field_yes_no('block', $block);
		echo '</td></tr>';
	}

	// Implement Module_Menu
	public function getMenu() {
		global $controller, $SEARCH_SPIDER, $WT_TREE;

		if ($SEARCH_SPIDER) {
			return null;
		}

		if (file_exists(WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/')) {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/style.css" type="text/css">';
		} else {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/webtrees/style.css" type="text/css">';
		}
		
		//-- main tasks menu item
		$menu = new Menu($this->getMenuTitle(), 'module.php?mod=' . $this->getName() . '&amp;mod_action=show', $this->getName());
		$tab=0;
		if ($this->getSetting('RT_TODO')) {
			$submenu = new Menu(I18N::translate('Research tasks'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-todo&amp;tab='.($tab++), 'task-todo');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_BIRT')) {
			$submenu = new Menu(I18N::translate('Missing birth dates'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-birt&amp;tab='.($tab++), 'task-birt');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_DEAT')) {
			$submenu = new Menu(I18N::translate('Missing death dates'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-deat&amp;tab='.($tab++), 'task-deat');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_MARR')) {
			$submenu = new Menu(I18N::translate('Missing marriage dates'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-marr&amp;tab='.($tab++), 'task-marr');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_DIV')) {
			$submenu = new Menu(I18N::translate('Missing divorce dates'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-div&amp;tab='.($tab++), 'task-div');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_INDI')) {
			$submenu = new Menu(I18N::translate('Unsourced individuals'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-indi&amp;tab='.($tab++), 'task-indi');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_FAM')) {
			$submenu = new Menu(I18N::translate('Unsourced families'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-fam&amp;tab='.($tab++), 'task-fam');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_CHIL')) {
			$submenu = new Menu(I18N::translate('Childless families'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-chil&amp;tab='.($tab++), 'task-chil');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_NOTE')) {
			$submenu = new Menu(I18N::translate('Individuals to check'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-note&amp;tab='.($tab++), 'task-note');
			$menu->addSubmenu($submenu);
		}
		if ($this->getSetting('RT_NOTE2')) {
			$submenu = new Menu(I18N::translate('Families to check'), 'module.php?mod='.$this->getName().'&amp;mod_action=show&amp;task=task-note2&amp;tab='.($tab++), 'task-note2');
			$menu->addSubmenu($submenu);
		}
		if (Auth::isManager($WT_TREE)) {
			$submenu = new Menu(I18N::translate('Configure'), $this->getConfigLink(), 'wooc_research-edit');
			$menu->addSubmenu($submenu);
		}
		return $menu;
	}

	// Extend Module
	public function modAction($mod_action) {
		switch($mod_action) {
		case 'show':
			$this->show();
			break;
		case 'admin_config':
			$this->config();
			break;
		}
	}

	private function config() {
		$header='if (document.createStyleSheet) {
				document.createStyleSheet("'.WT_MODULES_DIR.$this->getName().'/themes/'.Theme::theme()->themeId().'/style.css"); // For Internet Explorer
			} else {
				jQuery("head").append(\'<link rel="stylesheet" href="'.WT_MODULES_DIR.$this->getName().'/themes/'.Theme::theme()->themeId().'/style.css" type="text/css">\');
			}';
		$controller=new PageController;
		$controller
			->setPageTitle(I18N::translate('Task lists'))
			->pageHeader()
			->addInlineJavascript($header);
		$action=Filter::post('action');
		if ($action=='update') {
			$this->setSetting('RT_TODO', Filter::post('NEW_RT_TODO'));
			$this->setSetting('RT_BIRT', Filter::post('NEW_RT_BIRT'));
			$this->setSetting('RT_DEAT', Filter::post('NEW_RT_DEAT'));
			$this->setSetting('RT_MARR', Filter::post('NEW_RT_MARR'));
			$this->setSetting('RT_DIV' , Filter::post('NEW_RT_DIV' ));
			$this->setSetting('RT_INDI', Filter::post('NEW_RT_INDI'));
			$this->setSetting('RT_FAM' , Filter::post('NEW_RT_FAM' ));
			$this->setSetting('RT_CHIL', Filter::post('NEW_RT_CHIL'));
			$this->setSetting('RT_NOTE', Filter::post('NEW_RT_NOTE'));
			$this->setSetting('RT_NOTE_TEXT', Filter::post('NEW_RT_NOTE_TEXT'));
			$this->setSetting('RT_NOTE2', Filter::post('NEW_RT_NOTE2'));
			$this->setSetting('RT_NOTE2_TEXT', Filter::post('NEW_RT_NOTE2_TEXT'));
		}
		?>
		<ol class="breadcrumb small">
			<li><a href="admin.php"><?php echo I18N::translate('Control panel'); ?></a></li>
			<li><a href="admin_modules.php"><?php echo I18N::translate('Module administration'); ?></a></li>
			<li class="active"><?php echo $controller->getPageTitle(); ?></li>
		</ol>
		<h2><?php echo $controller->getPageTitle(); ?></h2>
		<p class="small text-muted"><?php echo $this->getDescription(); ?></p>
		<form class="form-horizontal" method="post" name="configform" action="<?php echo $this->getConfigLink(); ?>">
			<input type="hidden" name="action" value="update">
			<div id="tasklist" class="form-group">
			<div class="checkbox col-xs-12" dir="ltr">
				<span class="col-sm-12 text-muted">
					<?php echo I18N::translate('A list of tasks and activities that are linked to the family tree.'); ?>
				</span>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_TODO', $this->getSetting('RT_TODO')) . ' ' . I18N::translate('Research tasks'); ?>
				</label>
			</div>
			<div class="checkbox col-xs-12" dir="ltr">
				<span class="col-sm-12 text-muted">
					<?php echo I18N::translate('Lists of individual with not complete a birth or death date and families with not complete a marriage or divorce date.'); ?>
				</span>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_BIRT', $this->getSetting('RT_BIRT')) . ' ' . I18N::translate('Missing birth dates'); ?>
				</label>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_DEAT', $this->getSetting('RT_DEAT')) . ' ' . I18N::translate('Missing death dates'); ?>
				</label>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_MARR', $this->getSetting('RT_MARR')) . ' ' . I18N::translate('Missing marriage dates'); ?>
				</label>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_DIV', $this->getSetting('RT_DIV')) . ' ' . I18N::translate('Missing divorce dates'); ?>
				</label>
			</div>
			<div class="checkbox col-xs-12" dir="ltr">
				<span class="col-sm-12 text-muted">
					<?php echo I18N::translate('List of individuals and families that are unsourced.'); ?>
				</span>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_INDI', $this->getSetting('RT_INDI')) . ' ' . I18N::translate('Unsourced individuals'); ?>
				</label>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_FAM', $this->getSetting('RT_FAM')) . ' ' . I18N::translate('Unsourced families'); ?>
				</label>
			</div>
			<div class="checkbox col-xs-12" dir="ltr">
				<span class="col-sm-12 text-muted">
					<?php echo I18N::translate('List of families which do not have any children.'); ?>
				</span>
				<label class="checkbox-inline col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_CHIL', $this->getSetting('RT_CHIL')) . ' ' . I18N::translate('Childless families'); ?>
				</label>
			</div>
			<div class="checkbox col-xs-12" dir="ltr">
				<span class="col-sm-12 text-muted">
					<?php echo I18N::translate('List of records containing a special note.'); ?>
				</span>
				<label class="checkbox-inline col-sm-5 col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_NOTE', $this->getSetting('RT_NOTE')) . ' ' . I18N::translate('Individuals to check'); ?>
					<div class="input-group col-sm-12">
						<span class="input-group-addon" style="min-width:36px;"><?php echo I18N::translate('Note'); ?></span>
						<input
							class="form-control"
							id="NEW_RT_NOTE_TEXT"
							size="10"
							name="NEW_RT_NOTE_TEXT"
							type="text"
							style="max-width:340px;"
							value="<?php echo Filter::escapeHtml($this->getSetting('RT_NOTE_TEXT')); ?>"
							>
					</div>
				</label>
				<label class="checkbox-inline col-sm-5 col-sm-offset-1">
					<?php echo FunctionsEdit::twoStateCheckbox('NEW_RT_NOTE2', $this->getSetting('RT_NOTE2')) . ' ' . I18N::translate('Families to check'); ?>
					<div class="input-group col-sm-12">
						<span class="input-group-addon" style="min-width:36px;"><?php echo I18N::translate('Note'); ?></span>
						<input
							class="form-control"
							id="NEW_RT_NOTE2_TEXT"
							size="10"
							name="NEW_RT_NOTE2_TEXT"
							type="text"
							style="max-width:340px;"
							value="<?php echo Filter::escapeHtml($this->getSetting('RT_NOTE2_TEXT')); ?>"
							>
					</div>
				</label>
			</div>
			</div>
			<div class="row col-sm-9 col-sm-offset-3">
				<button class="btn btn-primary" type="submit">
					<i class="fa fa-check"></i>
					<?php echo I18N::translate('save'); ?>
				</button>
				<button class="btn btn-primary" type="reset" onclick="window.location='<?php echo $this->getConfigLink(); ?>';">
					<i class="fa fa-recycle"></i>
					<?php echo I18N::translate('cancel'); ?>
				</button>
			</div>
		</form>
		<?php
		$html='<div id="research-page">'.
			'<table id="research_edit">'.
			'<form method="post" name="configform" action="module.php?mod='.$this->getName().'&amp;mod_action=admin_config">'.
			'<input type="hidden" name="action" value="update">'.
			'<table id="task-lists_module">'.
			'<tr><th>'.
			'<h4>'.I18N::translate('A list of tasks and activities that are linked to the family tree.').'</h4>'.
			'<input type="checkbox" id="task-todo" name="NEW_RT_TODO"';
		if ($this->getSetting('RT_TODO')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-todo">'.I18N::translate('Research tasks').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<h4>'.I18N::translate('Lists of individual with not complete a birth or death date and families with not complete a marriage or divorce date.').'</h4>'.
			'<input type="checkbox" id="task-birt" name="NEW_RT_BIRT"';
		if ($this->getSetting('RT_BIRT')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-birt">'.I18N::translate('Missing birth dates').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<input type="checkbox" id="task-deat" name="NEW_RT_DEAT"';
		if ($this->getSetting('RT_DEAT')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-deat">'.I18N::translate('Missing death dates').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<input type="checkbox" id="task-marr" name="NEW_RT_MARR"';
		if ($this->getSetting('RT_MARR')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-marr">'.I18N::translate('Missing marriage dates').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<input type="checkbox" id="task-div" name="NEW_RT_DIV"';
		if ($this->getSetting('RT_DIV')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-div">'.I18N::translate('Missing divorce dates').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<h4>'.I18N::translate('List of individuals and families that are unsourced.').'</h4>'.
			'<input type="checkbox" id="task-indi" name="NEW_RT_INDI"';
		if ($this->getSetting('RT_INDI')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-indi">'.I18N::translate('Unsourced individuals').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<input type="checkbox" id="task-fam" name="NEW_RT_FAM"';
		if ($this->getSetting('RT_FAM')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-fam">'.I18N::translate('Unsourced families').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<h4>'.I18N::translate('List of families which do not have any children.').'</h4>'.
			'<input type="checkbox" id="task-child" name="NEW_RT_CHIL"';
		if ($this->getSetting('RT_CHIL')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-child">'.I18N::translate('Childless families').'</label>'.
			'</th></tr>'.
			'<tr><th>'.
			'<h4>'.I18N::translate('List of records containing a special note.').'</h4>'.
			'<input type="checkbox" id="task-child" name="NEW_RT_NOTE"';
		if ($this->getSetting('RT_NOTE')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-note">'.I18N::translate('Individuals to check').'</label>'.
			' <dd style="display:inline;">'.I18N::translate('Note').
			' <input type="text" name="NEW_RT_NOTE_TEXT" value="'.$this->getSetting('RT_NOTE_TEXT').'" size="20"></dd>'.
			'</th></tr>'.
			'<tr><th>'.
			'<input type="checkbox" id="task-note2" name="NEW_RT_NOTE2"';
		if ($this->getSetting('RT_NOTE2')) {
			$html.=' checked="checked"';
		}
		$html.='"><label for="task-note2">'.I18N::translate('Families to check').'</label>'.
			' <dd style="display:inline;">'.I18N::translate('Note').
			' <input type="text" name="NEW_RT_NOTE2_TEXT" value="'.$this->getSetting('RT_NOTE2_TEXT').'" size="20"></dd>'.
			'</th></tr>'.
			'</table>'.
			'<p><input type="submit" value="'.I18N::translate('Save').'">'.
			'&nbsp;<input type="button" value="'.I18N::translate('Cancel').'" onclick="window.location=\''.$this->getConfigLink().'\';" ></p>'.
			'</form>';
		//echo $html;
	}

	// Action from the configuration page
	private function edit() {

		if (Filter::postBool('save')) {
			$block_id=Filter::post('block_id');
			if ($block_id) {
				Database::prepare(
					"UPDATE `##block` SET gedcom_id=?, block_order=? WHERE block_id=?"
				)->execute(array(
					Filter::post('gedcom_id'),
					(int)Filter::post('block_order'),
					$block_id
				));
			} else {
				Database::prepare(
					"INSERT INTO `##block` (gedcom_id, module_name, block_order) VALUES (?, ?, ?)"
				)->execute(array(
					Filter::post('gedcom_id', array_keys(get_all_gedcoms())),
					$this->getName(),
					(int)Filter::post('block_order')
				));
				$block_id=Database::getInstance()->lastInsertId();
			}
			set_block_setting($block_id, 'show_research_tab', Filter::post('show_research_tab'));
		}
		$this->config();
	}

	private function show() {
		global $WT_TREE, $controller;

		$tab = Filter::getInteger('tab', 0);
		$ajax = Filter::getInteger('ajax', 0);
		$task = Filter::get('task', 0);
		$html='';
		if (!$ajax) {
			$js='jQuery(document).ready(function() {
				jQuery("#task-tabs").tabs({
					spinner: \'<i class="icon-loading-small"></i>\',
					cache: true
				});
				jQuery("#task-tabs").tabs("option", "active", '.$tab.' );
				jQuery("#task-tabs").css("visibility", "visible");
			});';
			$controller=new PageController();
			$controller->setPageTitle($this->getTitle())
				->addInlineJavascript($js)
				->pageHeader();
			$html.='<div id="task-details">
				<h2>'.$this->getTitle().'</h2>
				<div id="task-tabs">
				<ul>';
			$tab_html='';
			if ($this->getSetting('RT_TODO')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-todo"><span id="task-todo">'.I18N::translate('Research tasks').'</span></a></li>';
			}
			if ($this->getSetting('RT_BIRT')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-birt"><span id="task-birt">'.I18N::translate('Missing birth dates').'</span></a></li>';
			}
			if ($this->getSetting('RT_DEAT')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-deat"><span id="task-deat">'.I18N::translate('Missing death dates').'</span></a></li>';
			}
			if ($this->getSetting('RT_MARR')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-marr"><span id="task-marr">'.I18N::translate('Missing marriage dates').'</span></a></li>';
			}
			if ($this->getSetting('RT_DIV')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-div"><span id="task-div">'.I18N::translate('Missing divorce dates').'</span></a></li>';
			}
			if ($this->getSetting('RT_INDI')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-indi"><span id="task-indi">'.I18N::translate('Unsourced individuals').'</span></a></li>';
			}
			if ($this->getSetting('RT_FAM')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-fam"><span id="task-fam">'.I18N::translate('Unsourced families').'</span></a></li>';
			}
			if ($this->getSetting('RT_CHIL')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-chil"><span id="task-chil">'.I18N::translate('Childless families').'</span></a></li>';
			}
			if ($this->getSetting('RT_NOTE')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-note"><span id="task-note">'.I18N::translate('Individuals to check').'</span></a></li>';
			}
			if ($this->getSetting('RT_NOTE2')) {
				$tab_html.='<li><a href="module.php?mod='.$this->getName().'&amp;mod_action=show&amp;ajax=1&amp;task=task-note2"><span id="task-note">'.I18N::translate('Families to check').'</span></a></li>';
			}
			if (!$tab_html) {
				$html.='<p class="ui-state-highlight">'.I18N::translate('There are no research tasks in this family tree.').'</p>';
				if (Auth::isManager()) {
					$html.='<p><a class="icon-admin" href="'.$this->getConfigLink().'">'.I18N::translate('Configure').'</a></p>';
				}
			}
			$html.=$tab_html.'</ul>
				</div>'. //close div "task-tabs"
				'</div>'; //close div "task-details"
		} else {
			$controller=new AjaxController();
			$controller->pageHeader();
			if ($task=='task-todo') {
				$html.='<div id="task-todo">'.$this->get_tasks_list().'</div>';
			} else if ($task=='task-birt') {
				$html.='<div id="task-birt">'.FunctionsPrintLists::individualTable($this->get_indilist_wo_date('BIRT', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-deat') {
				$html.='<div id="task-deat">'.FunctionsPrintLists::individualTable($this->get_indilist_wo_date('DEAT', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-marr') {
				$html.='<div id="task-marr">'.FunctionsPrintLists::familyTable($this->get_famlist_wo_date ('MARR', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-div') {
				$html.='<div id="task-div">'.FunctionsPrintLists::familyTable($this->get_famlist_wo_date ('DIV', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-indi') {
				$html.='<div id="task-indi">'.FunctionsPrintLists::individualTable($this->get_unsourced_list('INDI', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-fam') {
				$html.='<div id="task-fam">'.FunctionsPrintLists::familyTable($this->get_unsourced_list('FAM', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-chil') {
				$html.='<div id="task-chil">'.FunctionsPrintLists::familyTable($this->get_famlist_wo_child('FAM', $WT_TREE->getTreeId())).'</div>';
			} else if ($task=='task-note') {
				$html.='<div id="task-note">'.FunctionsPrintLists::individualTable($this->get_specific_note_list('INDI', $WT_TREE->getTreeId(), $this->getSetting('RT_NOTE_TEXT'))).'</div>';
			} else if ($task=='task-note2') {
				$html.='<div id="task-note2">'.FunctionsPrintLists::familyTable($this->get_specific_note_list('FAM', $WT_TREE->getTreeId(), $this->getSetting('RT_NOTE2_TEXT'))).'</div>';
			}
		}
		echo $html;
	}
	
	private function get_indilist_wo_date($type='BIRT', $ged_id) {
		global $WT_TREE;
		$sql="SELECT DISTINCT 'INDI' AS type, i_id AS xref, i_file AS gedcom_id, i_gedcom AS gedcom, n_full ".
			"FROM `##individuals` ".
			"JOIN `##name` ON (n_id=i_id AND n_file=i_file) ".
			"LEFT JOIN `##dates` AS dates ON (dates.d_gid=i_id AND dates.d_file=i_file) ".
			"WHERE n_file={$ged_id} ".
			"AND n_type='NAME' ";
		if ($type=='BIRT') {
			$sql.="AND ((dates.d_fact='BIRT' AND (dates.d_day=0 OR dates.d_mon=0)) OR dates.d_gid IS NULL) ";
		} else if ($type=='DEAT') {
			$sql.="AND ((dates.d_fact='DEAT' AND (dates.d_day=0 OR dates.d_mon=0)) OR (dates.d_gid IS NULL AND i_gedcom LIKE '%\\n1 DEAT%') OR (dates.d_fact!='DEAT' AND i_gedcom LIKE '%\\n1 DEAT Y%')) ";
		} else return;
		$sql.="ORDER BY CASE n_surn WHEN '@N.N.' THEN 1 ELSE 0 END, n_surn COLLATE '".I18N::collation()."', CASE n_givn WHEN '@P.N.' THEN 1 ELSE 0 END, n_givn COLLATE '".I18N::collation()."'";

		$list=array();
		$rows=Database::prepare($sql)->fetchAll();
		foreach ($rows as $row) {
			$person=Individual::getInstance($row->xref, $WT_TREE);
			// The name from the database may be private - check the filtered list...
			foreach ($person->getAllNames() as $n=>$name) {
				if ($name['fullNN']==$row->n_full) {
					$person->setPrimaryName($n);
					// We need to clone $person, as we may have multiple references to the
					// same person in this list, and the "primary name" would otherwise
					// be shared amongst all of them.
					$list[]=clone $person;
					break;
				}
			}
		}
		return $list;
	}

	private function get_famlist_wo_date($type='MARR', $ged_id) {
		global  $WT_TREE;
		$sql="SELECT DISTINCT 'FAM' AS type, f_id AS xref, f_file AS gedcom_id, f_gedcom AS gedcom, f_husb, f_wife, f_numchil ".
			"FROM `##families` ".
			"LEFT JOIN `##dates` AS dates ON (f_id=dates.d_gid AND f_file=dates.d_file) ";
		if ($type=='MARR') {
			$sql.=" WHERE (dates.d_fact='MARR' AND (dates.d_day=0 OR dates.d_mon=0)) OR (f_gedcom NOT LIKE '%_NMR%' AND dates.d_gid IS NULL)";
		} else if ($type=='DIV') {
			$sql.=" WHERE dates.d_fact='DIV' AND (dates.d_day=0 OR dates.d_mon=0)";
		} else return;

		$rows=Database::prepare($sql)->fetchAll();
		foreach ($rows as $row) {
			$list[]=Family::getInstance($row->xref, $WT_TREE);
		}
		usort($list, '\Fisharebest\Webtrees\GedcomRecord::compare');
		return $list;
	}

	private function get_unsourced_list($type='INDI', $ged_id) {
		global $WT_TREE;
		$sql="SELECT DISTINCT";
		$list=array();
		if ($type=='INDI') {
			$sql.=" 'INDI' AS type,
					i_id AS xref,
					i_file AS gedcom_id,
					i_gedcom AS gedcom,
					n_full
					FROM `##individuals`
					JOIN `##name` ON (i_id=n_id AND i_file=n_file)
					LEFT JOIN `##link` ON (i_id=l_from AND i_file=l_file AND l_type='SOUR') 
					WHERE n_file={$ged_id} AND
					n_type='NAME' AND
					l_to IS NULL
					ORDER BY
					CASE n_surn WHEN '@N.N.' THEN 1 ELSE 0 END, n_surn COLLATE '".I18N::collation()."',
					CASE n_givn WHEN '@P.N.' THEN 1 ELSE 0 END, n_givn COLLATE '".I18N::collation()."'";
			$rows=Database::prepare($sql)->fetchAll();
			foreach ($rows as $row) {
				$person=Individual::getInstance($row->xref, $WT_TREE);
				// The name from the database may be private - check the filtered list...
				foreach ($person->getAllNames() as $n=>$name) {
					if ($name['fullNN']==$row->n_full) {
						$person->setPrimaryName($n);
						$list[]=clone $person;
						break;
					}
				}
			}
		} else if ($type=='FAM') {
			$sql.=" 'FAM' AS type,
					f_id AS xref,
					f_file AS gedcom_id,
					f_gedcom AS gedcom,
					f_husb, f_wife, f_numchil
					FROM `##families`
					LEFT JOIN `##link` ON (f_id=l_from AND f_file=l_file AND l_type='SOUR') 
					WHERE f_file={$ged_id} AND
					l_to IS NULL";
			$rows=Database::prepare($sql)->fetchAll();
			foreach ($rows as $row) {
				$list[]=Family::getInstance($row->xref, $WT_TREE);
			}
			usort($list, '\Fisharebest\Webtrees\GedcomRecord::compare');
		} else {
			return;
		}
		return $list;
	}

	private function get_famlist_wo_child($type='FAM', $ged_id) {
		global $WT_TREE;
		$sql="SELECT DISTINCT 'FAM' AS type, f_id AS xref, f_file AS ged_id, f_gedcom AS gedrec
			FROM `##families` 
			WHERE f_numchil = 0 AND 
			f_gedcom NOT LIKE '%1 NCHI 0%' AND 
			f_file={$ged_id}";
		$rows=Database::prepare($sql)->fetchAll();
		foreach ($rows as $row) {
			$list[]=Family::getInstance($row->xref, $WT_TREE);
		}
		usort($list, '\Fisharebest\Webtrees\GedcomRecord::compare');
		return $list;
	}
	
	private function get_specific_note_list($type='INDI', $ged_id, $note='') {
		global $WT_TREE;
		$sql="SELECT DISTINCT";
		$list=array();
		if ($type=='INDI') {
			$sql.=" 'INDI' AS type,
					i_id AS xref,
					i_file AS gedcom_id,
					i_gedcom AS gedcom,
					n_full
					FROM `##individuals`
					JOIN `##name` ON (i_id=n_id AND i_file=n_file)
					WHERE n_file={$ged_id} AND
					n_type='NAME' AND
					i_gedcom LIKE '%".$note."%'
					ORDER BY
					CASE n_surn WHEN '@N.N.' THEN 1 ELSE 0 END, n_surn COLLATE '".I18N::collation()."',
					CASE n_givn WHEN '@P.N.' THEN 1 ELSE 0 END, n_givn COLLATE '".I18N::collation()."'";
			$rows=Database::prepare($sql)->fetchAll();
			foreach ($rows as $row) {
				$person=Individual::getInstance($row->xref, $WT_TREE);
				// The name from the database may be private - check the filtered list...
				foreach ($person->getAllNames() as $n=>$name) {
					if ($name['fullNN']==$row->n_full) {
						$person->setPrimaryName($n);
						$list[]=clone $person;
						break;
					}
				}
			}
		} else if ($type=='FAM') {
			$sql.=" 'FAM' AS type,
					f_id AS xref,
					f_file AS ged_id,
					f_gedcom AS gedrec,
					f_husb, f_wife, f_numchil
					FROM `##families`
					WHERE f_file={$ged_id} AND
					f_gedcom LIKE '%".$note."%'";
			$rows=Database::prepare($sql)->fetchAll();
			foreach ($rows as $row) {
				$list[]=Family::getInstance($row->xref, $WT_TREE);
			}
			usort($list, '\Fisharebest\Webtrees\GedcomRecord::compare');
		} else {
			return;
		}
		return $list;
	}

	private function get_tasks_list($show_unassigned=true, $show_other=true, $show_future=true) {
		global $WT_TREE, $controller;

		$table_id = 'ID'.floor(microtime()*1000000); // create a unique ID
		$controller
			->addExternalJavascript(WT_JQUERY_DATATABLES_JS_URL)
			->addInlineJavascript('
				jQuery("#'.$table_id.'").dataTable( {
				"sDom": \'t\',
				'.I18N::datatablesI18N().',
				"bAutoWidth":false,
				"bPaginate": false,
				"bLengthChange": false,
				"bFilter": false,
				"bInfo": true,
				"bJQueryUI": true,
				"aoColumns": [
					/* 0-DATE */   		{ "bVisible": false },
					/* 1-Date */		{ "iDataSort": 0 },
					/* 1-Record */ 		{},
					/* 2-Username */	{},
					/* 3-Text */		{}
				]
				});		
			jQuery("#'.$table_id.'").css("visibility", "visible");
			jQuery(".loading-image").css("display", "none");
			');
		$content='';
		$content .= '<div class="loading-image">&nbsp;</div>';
		$content .= '<table id="'.$table_id.'" style="visibility:hidden;">';
		$content .= '<thead><tr>';
		$content .= '<th>DATE</th>'; //hidden by datables code
		$content .= '<th>'.GedcomTag::getLabel('DATE').'</th>';
		$content .= '<th>'.I18N::translate('Record').'</th>';
		if ($show_unassigned || $show_other) {
			$content .= '<th>'.I18N::translate('Username').'</th>';
		}
		$content .= '<th>'.GedcomTag::getLabel('TEXT').'</th>';
		$content .= '</tr></thead><tbody>';

		$found=false;
		$end_jd=$show_future ? 99999999 : WT_CLIENT_JD;
		foreach (FunctionsDb::getCalendarEvents(0, $end_jd, '_TODO', $WT_TREE) as $todo) {
			$record=GedcomRecord::getInstance($todo['id']);
			if ($record && $record->canDisplayDetails()) {
				$user_name=get_gedcom_value('_WT_USER', 2, $todo['factrec']);
				if ($user_name==WT_USER_NAME || !$user_name && $show_unassigned || $user_name && $show_other) {
					$content.='<tr>';
					//-- Event date (sortable)
					$content .= '<td>'; //hidden by datables code
					$content .= $todo['date']->JD();
					$content .= '</td>';
					$content.='<td class="wrap">'. $todo['date']->Display(empty($SEARCH_SPIDER)).'</td>';
					$name=$record->getFullName();
					$content.='<td class="wrap"><a href="'.$record->getHtmlUrl().'">'.$name.'</a></td>';
					if ($show_unassigned || $show_other) {
						$content.='<td class="wrap">'.$user_name.'</td>';
					}
					$text=get_gedcom_value('_TODO', 1, $todo['factrec']);
					$content.='<td class="wrap">'.$text.'</td>';
					$content.='</tr>';
					$found=true;
				}
			}
		}
		$content .= '</tbody></table>';
		if (!$found) {
			$content='<p>'.I18N::translate('There are no research tasks in this family tree.').'</p>';
		}
		return $content;
	}
}

return new WoocResearchModule;