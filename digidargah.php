<?php

/*
* Plugin Name: افزونه دیجی درگاه برای گرویتی فرم
* description: افزونه درگاه پرداخت رمز ارزی <a href="https://digidargah.com"> دیجی درگاه </a> برای گرویتی فرم
* Version: 1.1
* developer: Hanif Zekri Astaneh
* Author: دیجی درگاه
* Author URI: https://digidargah.com
* Author Email: info@digidargah.com
* Text Domain: digidargah_gf_payment_plugin
* Tested version up to: 6.1
* copyright (C) 2020 digidargah
* license http://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
*/

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, array('GF_digidargah_Gateway', "add_permissions"));
add_action('init', array('GF_digidargah_Gateway', 'init'));

class GF_digidargah_Database {
	
	private static $method = 'digidargah';
	
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . "gf_digidargah";
	}

	public static function get_entry_table_name() {
		$version = GFCommon::$version;
		if (method_exists('GFFormsModel', 'get_database_version')) $version = GFFormsModel::get_database_version();
		return version_compare($version, '2.3-dev-1', '<')?GFFormsModel::get_lead_table_name():GFFormsModel::get_entry_table_name();
	}

	public static function get_available_forms() {
		$forms = RGFormsModel::get_forms();
		$available_forms = array();
		foreach ($forms as $form) $available_forms[] = $form;
		return $available_forms;
	}

	public static function get_feed($id) {
		global $wpdb;
		$table_name = self::get_table_name();
		$sql = $wpdb->prepare("select id, form_id, is_active, meta from $table_name where id=%d", $id);
		$result = $wpdb->get_results($sql, ARRAY_A);
		if (empty($result)) return array();
		$result = $result[0];
		$result["meta"] = maybe_unserialize($result["meta"]);
		return $result;
	}

	public static function get_feeds() {
		global $wpdb;
		$table_name = self::get_table_name();
		$form_table_name = RGFormsModel::get_form_table_name();
		$sql = "select s.id, s.is_active, s.form_id, s.meta, f.title as form_title from $table_name s inner join $form_table_name f on s.form_id = f.id";
		$results = $wpdb->get_results($sql, ARRAY_A);
		$count = sizeof($results);
		for ($i = 0; $i < $count; $i ++) $results[ $i ]["meta"] = maybe_unserialize($results[ $i ]["meta"]);
		return $results;
	}

	public static function get_feed_by_form($form_id, $only_active = false) {
		global $wpdb;
		$table_name = self::get_table_name();
		$active_clause = $only_active?" and is_active=1":"";
		$sql = $wpdb->prepare("select id, form_id, is_active, meta from $table_name where form_id=%d $active_clause", $form_id);
		$results = $wpdb->get_results($sql, ARRAY_A);
		if (empty($results)) return array();
		$count = sizeof($results);
		for ($i = 0; $i < $count; $i ++) $results[ $i ]["meta"] = maybe_unserialize($results[ $i ]["meta"]);
		return $results;
	}

	public static function update_feed($id, $form_id, $is_active, $setting) {
		global $wpdb;
		$table_name = self::get_table_name();
		$setting = maybe_serialize($setting);
		if ($id == 0) {
			$wpdb->insert($table_name, array("form_id" => $form_id, "is_active" => $is_active, "meta" => $setting), array("%d", "%d", "%s"));
			$id = $wpdb->get_var("select LAST_INSERT_ID()");
		} else {
			$wpdb->update($table_name, array("form_id" => $form_id, "is_active" => $is_active, "meta" => $setting), array("id" => $id), array("%d", "%d", "%s"), array("%d"));
		}
		return $id;
	}

	public static function delete_feed($id) {
		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->query($wpdb->prepare("DELETE from $table_name where id=%s", $id));
	}
}

class GF_digidargah_Gateway {

	private static $version = '1.1';
	private static $min_gravityforms_version = "2.1";
	private static $config = null;
	
	private static $markups = '
	<script> var $ = jQuery.noConflict();//wordpress bug for not using $ as jquery selector </script>
	<style type="text/css">
	a {cursor: pointer;}
	.clear {clear: both;}
	.updated {padding: 15px 20px !important; font-weight: bolder;}
	.gf_browser_gecko {width: 100%;}
	.form-list-head h2 {margin: 0 0 0 10px;}
	.check-column {padding: 10px !important;}
	.gform-settings__wrapper .button {margin: 0 10px !important;}
	.gf_no_conditional_message {display: none; background-color: #FFDFDF; margin-top: 4px; margin: 10px 0; padding: 15px; box-shadow: 0 0 5px #C89797;}
	.gficon_link {float: left; padding: 5px; cursor: pointer;}
	.row-actions {width: 280px;}
	.gform-settings-label {padding: 0 5px;}
	.gform-settings-description {padding: 0 5px; font-size: 12px; line-height: 18px;}
	</style>
	';

	public static function init() {

		if (!self::is_gravityforms_supported()) {
			add_action('admin_notices', array(__CLASS__, 'admin_notice_gf_support'));
			return false;
		}
		
		if (!function_exists('wp_get_current_user')) include(ABSPATH . "wp-includes/pluggable.php");
		$has_access = GFCommon::current_user_can_any('gravityforms_digidargah');

		if (is_admin() && $has_access) {

			add_filter('gform_addon_navigation', array(__CLASS__, 'menu'));
			add_action('gform_entry_info', array(__CLASS__, 'payment_entry_detail'), 4, 2);
			add_action('gform_after_update_entry', array(__CLASS__, 'update_payment_entry'), 4, 2);

			if (get_option("gf_digidargah_configured")) {
				add_filter('gform_form_settings_menu', array(__CLASS__, 'toolbar'), 10, 2);
				add_action('gform_form_settings_page_digidargah', array(__CLASS__, 'feed_page'));
			}

			if (rgget("page") == "gf_settings") {
				RGForms::add_settings_page(array(
					'name' => 'gf_digidargah',
					'tab_label' => __('دیجی درگاه', 'gravityformsdigidargah'),
					'title' => __('تنظیم های دیجی درگاه', 'gravityformsdigidargah'),
					'handler' => array(__CLASS__, 'settings_page'),
				));
			}
			
			$current_page = trim(strtolower(RGForms::get("page")));		
			if (in_array($current_page, array('gf_digidargah', 'digidargah'))) wp_enqueue_script(array("sack"));
			
			if (get_option("gf_digidargah_version") != self::$version) {
				
				global $wpdb;
				$table_name = GF_digidargah_Database::get_table_name();
				$charset_collate = '';
					
				if (!empty($wpdb->charset)) $charset_collate = "default character set $wpdb->charset";
				if (!empty($wpdb->collate)) $charset_collate .= " collate $wpdb->collate";
					
				$sql = "create table if not exists $table_name (
						id mediumint(8) unsigned not null auto_increment,
						form_id mediumint(8) unsigned not null,
						is_active tinyint(1) not null default 1,
						meta longtext,
						primary key  (id),
						key form_id (form_id)) $charset_collate;";
							
				require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
				$wpdb->get_results($sql);
				
				update_option("gf_digidargah_version", self::$version);
			}
				
			add_action('wp_ajax_gf_digidargah_update_feed_active', array(__CLASS__, 'update_feed_active'));
		}
		
		if (get_option('gf_digidargah_configured')) {
			add_filter('gform_disable_post_creation', array(__CLASS__, 'delay_posts'), 10, 3);
			add_filter('gform_is_delayed_pre_process_feed', array(__CLASS__, 'delay_addons'), 10, 4);
			add_filter('gform_confirmation', array(__CLASS__, 'request'), 1000, 4);
			add_action('wp', array(__CLASS__, 'verify'), 5);
		}
		
		add_filter('gform_currencies', array(__CLASS__, 'irt_currency'));
		add_filter('gform_logging_supported', array(__CLASS__, 'set_logging_supported'));
		add_filter('gf_payment_gateways', array(__CLASS__, 'gravityformsdigidargah'), 2);
		do_action('gravityforms_gateways');
		do_action('gravityforms_digidargah');
	}
	
	public static function irt_currency($currencies) {
		$currencies['IRT'] = array(
			'name' => __('تومان', 'gravityforms'),
			'code' => 'IRT',
			'symbol_left' => '&#8377;',
			'symbol_right' => '',
			'symbol_padding' => ' ',
			'thousand_separator' => ',',
			'decimal_separator' => '.',
			'decimals' => 2
		);
		return $currencies;
	}

	public static function admin_notice_gf_support() {
		sprintf(__('<div class="update notice-error"> افزونه دیجی درگاه به گرویتی فرم نسخه %s و یا بالاتر نیاز دارد. </div>', "gravityformsdigidargah"), self::$min_gravityforms_version);
	}

	public static function gravityformsdigidargah($form, $entry) {
		$digidargah = array(
			'class' => (__CLASS__ . '|digidargah'),
			'title' => __('دیجی درگاه', 'gravityformsdigidargah'),
			'param' => array('desc'   => __('توضیحات', 'gravityformsdigidargah'))
		);

		return apply_filters('gf_digidargah_detail', apply_filters('gf_gateway_detail', $digidargah, $form, $entry), $form, $entry);
	}

	public static function add_permissions() {
		global $wp_roles;
		$editable_roles = get_editable_roles();
		foreach ((array) $editable_roles as $role => $details) {
			if ($role == 'administrator' || in_array('gravityforms_edit_forms', $details['capabilities']))
				$wp_roles->add_cap($role, 'gravityforms_digidargah');
		}
	}

	public static function menu($menus) {
		$permission = "gravityforms_digidargah";
		if (!empty($permission)) {
			$menus[] = array(
				"name" => "gf_digidargah",
				"label" => __("دیجی درگاه", "gravityformsdigidargah"),
				"callback" => array(__CLASS__, "digidargah_page"),
				"permission" => $permission
			);
		}
		return $menus;
	}

	public static function toolbar($menu_items) {
		$menu_items[] = array('name'=>'digidargah', 'label'=>__('دیجی درگاه', 'gravityformsdigidargah'));
		return $menu_items;
	}

	private static function is_gravityforms_supported() {
		if (class_exists("GFCommon")) {
			$is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
			return $is_correct_version;
		} else
			return false;
	}

	public static function set_logging_supported($plugins) {
		$plugins[ basename(dirname(__FILE__)) ] = "digidargah";
		return $plugins;
	}

	public static function feed_page() {
		self::list_page('per-form');
	}

	public static function has_digidargah_condition($form, $config) {

		if (empty($config['meta'])) return false;
		if (empty($config['meta']['digidargah_conditional_enabled'])) return true;

		if (!empty($config['meta']['digidargah_conditional_field_id'])) {
			$condition_field_ids = $config['meta']['digidargah_conditional_field_id'];
			if (!is_array($condition_field_ids))
				$condition_field_ids = array('1' => $condition_field_ids);
		} else
			return true;

		if (!empty($config['meta']['digidargah_conditional_value'])) {
			$condition_values = $config['meta']['digidargah_conditional_value'];
			if (!is_array($condition_values))
				$condition_values = array('1' => $condition_values);
		} else
			$condition_values = array('1' => '');

		if (!empty($config['meta']['digidargah_conditional_operator'])) {
			$condition_operators = $config['meta']['digidargah_conditional_operator'];
			if (!is_array($condition_operators))
				$condition_operators = array('1' => $condition_operators);
		} else
			$condition_operators = array('1' => 'is');

		$type = !empty($config['meta']['digidargah_conditional_type'])?strtolower($config['meta']['digidargah_conditional_type']):'';
		$type = $type == 'all'?'all':'any';

		foreach ($condition_field_ids as $i => $field_id) {

			if (empty($field_id)) continue;
			$field = RGFormsModel::get_field($form, $field_id);
			if (empty($field))continue;

			$value = !empty($condition_values[ '' . $i . '' ])?$condition_values[ '' . $i . '' ]:'';
			$operator = !empty($condition_operators[ '' . $i . '' ])?$condition_operators[ '' . $i . '' ]:'is';

			$is_visible = !RGFormsModel::is_field_hidden($form, $field, array());
			$field_value = RGFormsModel::get_field_value($field, array());
			$is_value_match = RGFormsModel::is_value_match($field_value, $value, $operator);
			$check = $is_value_match && $is_visible;

			if ($type == 'any' && $check) return true;
			else if ($type == 'all' && !$check) return false;
		}

		if ($type == 'any') return false;
		else return true;
	}

	public static function get_config_by_entry($entry) {
		$feed_id = gform_get_meta($entry["id"], "digidargah_feed_id");
		$feed    = !empty($feed_id)?GF_digidargah_Database::get_feed($feed_id):'';
		$return  = !empty($feed)?$feed:false;
		return apply_filters('gf_digidargah_get_config_by_entry', apply_filters('gf_gateway_get_config_by_entry', $return, $entry), $entry);
	}

	public static function delay_posts($is_disabled, $form, $entry) {
		$config = self::get_active_config($form);
		if (!empty($config) && is_array($config) && $config) return true;
		return $is_disabled;
	}

	public static function delay_addons($is_delayed, $form, $entry, $slug) {

		$config = self::get_active_config($form);

		if (!empty($config["meta"]) && is_array($config["meta"]) && $config = $config["meta"]) {

			$user_registration_slug = apply_filters('gf_user_registration_slug', 'gravityformsuserregistration');

			if ($slug != $user_registration_slug && !empty($config["addon"]) && $config["addon"] == 'true')
				$flag = true;
			else if ($slug == $user_registration_slug && !empty($config["type"]) && $config["type"] == "subscription")
				$flag = true;

			if (!empty($flag)) {
				$fulfilled = gform_get_meta($entry['id'], $slug . '_is_fulfilled');
				$processed = gform_get_meta($entry['id'], 'processed_feeds');
				$is_delayed = empty($fulfilled) && rgempty($slug, $processed);
			}
		}

		return $is_delayed;
	}

	private static function redirect_confirmation($url, $ajax) {
		if (headers_sent() || $ajax) {
			$confirmation = "<script type=\"text/javascript\">" . apply_filters('gform_cdata_open', '') . " function gformRedirect(){document.location.href='$url';}";
			if (!$ajax) $confirmation .= 'gformRedirect();';
			$confirmation .= apply_filters('gform_cdata_close', '') . '</script>';
		} else
			$confirmation = array('redirect' => $url);
		return $confirmation;
	}

	public static function get_active_config($form) {

		if (!empty(self::$config)) return self::$config;
		$configs = GF_digidargah_Database::get_feed_by_form($form["id"], true);
		$configs = apply_filters('gf_digidargah_get_active_configs', apply_filters('gf_gateway_get_active_configs', $configs, $form), $form);

		$return = false;

		if (!empty($configs) && is_array($configs)) {
			foreach ($configs as $config) {
				if (self::has_digidargah_condition($form, $config)) $return = $config;
				break;
			}
		}

		self::$config = apply_filters('gf_digidargah_get_active_config', apply_filters('gf_gateway_get_active_config', $return, $form), $form);

		return self::$config;
	}

	public static function digidargah_page() {
		$view = rgget("view");
		if ($view == "edit") self::config_page();
		else self::list_page('');
	}

	private static function list_page($arg) {
		
		if (!self::is_gravityforms_supported()) {
			add_action('admin_notices', array(__CLASS__, 'admin_notice_gf_support'));
			return false;
		}
			
		wp_enqueue_style('gform_settings_digidargah', GFCommon::get_base_url() . '/assets/css/dist/settings.min.css');
		wp_enqueue_style('gform_admin_digidargah', GFCommon::get_base_url() . '/assets/css/dist/admin.min.css');
		wp_print_styles(array('jquery-ui-styles', 'gform_admin_digidargah', 'wp-pointer'));
		
		echo self::$markups;
		
		?>
		
		<div class="wrap gforms_edit_form gforms_form_settings_wrap gf_browser_gecko">
		
		<?php if ($arg == '') { ?>
		<header class="gform-settings-header">
		<div class="gform-settings__wrapper">
		<img src="<?= GFCommon::get_base_url(); ?>/images/logos/gravity-logo-white.svg" alt="Gravity Forms" width="266">
		<div class="gform-settings-header_buttons"></div>
		</div>
		</header>
		<!-- WP appends notices to the first H tag, so this is here to capture admin notices. -->
		<div id="gf-admin-notices-wrapper"><h2 class="gf-notice-container"></h2></div>
		<?php } ?>
		
		<div class="gform-settings__wrapper gform-settings__wrapper--full">
		<div class="gform-settings__content">
		<div class="gform-settings-panel__content form-list">
		<div class="wrap gforms_edit_form gf_browser_gecko">
		<?php
		
		if (rgpost('action') == "delete") {
			check_admin_referer("list_action", "gf_digidargah_list");
			$id = absint(rgpost("action_argument"));
			GF_digidargah_Database::delete_feed($id);
			?>
			<div class="updated fade"><?php _e("فید با موفقیت حذف شد", "gravityformsdigidargah") ?></div>
			<?php
		
		} else if (!empty($_POST["bulk_action"])) {
			
			check_admin_referer("list_action", "gf_digidargah_list");
			$selected_feeds = rgpost("feed");
			if (is_array($selected_feeds)) {
				foreach ($selected_feeds as $feed_id) {
					GF_digidargah_Database::delete_feed($feed_id);
				}
			}
			
			?>
			<div class="updated fade"><?php _e("فید ها با موفقیت حذف شدند", "gravityformsdigidargah") ?></div>
			<?php
		}
		
		if ($arg == '') { ?>
			
			<div class="form-list-head">
			<h2><?php _e("فرم های دیجی درگاه", "gravityformsdigidargah"); ?></h2>
			</div>
			
			<div class="hr-divider"></div>
			
		<?php } else { ?>
		
			<div class="gform-settings__content">
			<div class="gform-settings-panel">
			
			<header class="gform-settings-panel__header">
			<h4 class="gform-settings-panel__title"><?= __("فیدهای فرم", "gravityformsdigidargah"); ?></h4>
			</header>
			
			<div class="gform-settings-panel__content">
			
		<?php } ?>
		
		<form id="confirmation_list_form" method="post">
		<?php wp_nonce_field('list_action', 'gf_digidargah_list') ?>
		<input type="hidden" id="action" name="action" value=""/>
		<input type="hidden" id="action_argument" name="action_argument" value=""/>
		
		<div class="tablenav top">
		
		<div class="alignleft actions bulkactions">
		<label for="bulk_action" class="screen-reader-text"><?php _e("اقدام گروهی", "gravityformsdigidargah") ?></label>
		<select name="bulk_action" id="bulk_action" autocomplete="on">
		<option value=''> <?php _e("اقدام گروهی", "gravityformsdigidargah") ?> </option>
		<option value='delete'><?php _e("حذف", "gravityformsdigidargah") ?></option>
		</select>
		<input type="submit" id="doaction" class="button action" value="<?=  __("اعمال", "gravityformsdigidargah"); ?>" onclick="return confirm('<?= __("مطمئن هستید ؟", "gravityformsdigidargah"); ?>');">
		</div>
		
		<?php if (get_option("gf_digidargah_configured")) { ?>
			<div class="alignright">
			<a class="button gform-add-new-form primary add-new-h2" href="admin.php?page=gf_digidargah&view=edit<?= (rgget('id') > 0)?('&fid=' . rgget('id')):''; ?>"><?php _e("فید جدید", "gravityformsdigidargah") ?></a>
			</div>
		<?php } ?>
		
		</div>
		
		<table class="wp-list-table widefat fixed striped toplevel_page_gf_edit_forms" cellspacing="0">
		
		<thead>
		<tr>
		<th scope="col" id="cb" class="manage-column column-cb check-column" ><input type="checkbox"/></th>
		<?php if ($arg != 'per-form') { ?>
		<th scope="col" id="active" class="manage-column"><?= __('وضعیت', 'gravityformsdigidargah'); ?></th>
		<?php } ?>
		<th scope="col" class="manage-column"><?php _e(" آیدی فید", "gravityformsdigidargah") ?></th>
		<?php if ($arg != 'per-form') { ?>
		<th scope="col" class="manage-column"><?php _e("فرم متصل به درگاه", "gravityformsdigidargah") ?></th>
		<?php } ?>
		<th scope="col" class="manage-column"><?php _e("نوع تراکنش", "gravityformsdigidargah") ?></th>
		</tr>
		</thead>
		
		<tfoot>
		<tr>
		<th scope="col" id="cb" class="manage-column column-cb check-column" ><input type="checkbox"/></th>
		<?php if ($arg != 'per-form') { ?>
		<th scope="col" id="active" class="manage-column"><?= __('وضعیت', 'gravityformsdigidargah'); ?></th>
		<?php } ?>
		<th scope="col" class="manage-column"><?php _e(" آیدی فید", "gravityformsdigidargah") ?></th>
		<?php if ($arg != 'per-form') { ?>
		<th scope="col" class="manage-column"><?php _e("فرم متصل به درگاه", "gravityformsdigidargah") ?></th>
		<?php } ?>
		<th scope="col" class="manage-column"><?php _e("نوع تراکنش", "gravityformsdigidargah") ?></th>
		</tr>
		</tfoot>
		
		<tbody class="list:user user-list">
		<?php
		
		if ($arg != 'per-form') $settings = GF_digidargah_Database::get_feeds();
		else $settings = GF_digidargah_Database::get_feed_by_form(rgget('id'), false);
		
		if (!get_option("gf_digidargah_configured")) { ?>
			<tr>
			<td colspan="5">
			<?= sprintf(__("برای شروع استفاده از افزونه، ابتدا می بایست درگاه را فعال نمایید. %sبه تنظیم های دیجی درگاه بروید.%s", "gravityformsdigidargah"), '<a href="admin.php?page=gf_settings&subview=gf_digidargah">', "</a>"); ?>
			</td>
			</tr>
		<?php
		} else if (is_array($settings) && sizeof($settings) > 0) {
			foreach ($settings as $setting) { ?>
				<tr class='author-self status-inherit' valign="top">
				<th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?= $setting["id"] ?>"/></th>
				<td>
				<img src="<?= esc_url(GFCommon::get_base_url()) ?>/images/active<?= intval($setting["is_active"]) ?>.png" alt="<?= $setting["is_active"]?__("درگاه فعال است", "gravityformsdigidargah"):__("درگاه غیر فعال است", "gravityformsdigidargah"); ?>" title="<?= $setting["is_active"]?__("درگاه فعال است", "gravityformsdigidargah"):__("درگاه غیر فعال است", "gravityformsdigidargah"); ?>" onclick="ToggleActive(this, <?= $setting['id'] ?>); "/>
				</td>
				<td>
				<?php
				echo $setting["id"];
				if ($arg == 'per-form') { ?>
					<div class="row-actions">
					<a class="edit" title="<?php _e("ویرایش فید", "gravityformsdigidargah") ?>" href="admin.php?page=gf_digidargah&view=edit&id=<?= $setting["id"] ?>"><?php _e("ویرایش فید", "gravityformsdigidargah") ?></a> | 
					<a title="<?php _e("حذف", "gravityformsdigidargah") ?>" onclick="deleteSetting(<?= $setting["id"] ?>)"><?php _e("حذف", "gravityformsdigidargah") ?></a>
					</div>
				<?php } ?>
				</td>
				
				<?php if ($arg != 'per-form') { ?>
					<td class="column-title">
					
					<strong><a class="row-title" href="admin.php?page=gf_digidargah&view=edit&id=<?= $setting["id"] ?>" title="<?php _e("ویرایش فید", "gravityformsdigidargah") ?>"><?= $setting["form_title"] ?></a></strong>
					
					<div class="row-actions">
					<span class="edit">
					<a href="admin.php?page=gf_digidargah&view=edit&id=<?= $setting["id"] ?>"><?php _e("ویرایش فید", "gravityformsdigidargah") ?></a> | 
					</span>
					<span class="trash">
					<a onclick="deleteSetting(<?= $setting["id"] ?>)"><?php _e("حذف", "gravityformsdigidargah") ?></a> | 
					</span>
					<span class="view">
					<a href="admin.php?page=gf_edit_forms&id=<?= $setting["form_id"] ?>"><?php _e("ویرایش فرم", "gravityformsdigidargah") ?></a> | 
					</span>
					<span class="view">
					<a href="admin.php?page=gf_entries&view=entries&id=<?= $setting["form_id"] ?>"><?php _e("صندوق ورودی", "gravityformsdigidargah") ?></a>
					</span>
					</div>
					</td>
				<?php } ?>
				
				<td class="column-date">
				<?php
				if (isset($setting["meta"]["type"]) && $setting["meta"]["type"] == 'subscription')
					_e("عضویت", "gravityformsdigidargah");
				else
					_e("محصول معمولی یا فرم ارسال پست", "gravityformsdigidargah");
				?>
				</td>
				</tr>
			<?php } ?>
		<?php } else { ?>
			<tr>
			<td colspan="5">
			<?= sprintf(__("موردی برای نمایش یافت نشد. %sبرای ساخت فید جدید کلیک نمایید%s.", "gravityformsnoborder"), '<a href="admin.php?page=gf_noborder&view=edit' . (($arg == 'per-form')?('&fid=' . absint(rgget("id"))):'') . '">', "</a>"); ?>
			</td>
			</tr>
		<?php } ?>
		</tbody>
		</table>
		</form>
		
		<?php if ($arg != '') { ?>
			</div>
			</div>
			</div>
		<?php } ?>
		
		</div>
		
		<script>
		
		function deleteSetting(id) {
			if(!confirm("مطمئن هستید ?")) return false;
			$("#action_argument").val(id);
			$("#action").val("delete");
			$("#confirmation_list_form")[0].submit();
		}
		
		function ToggleActive(img, feed_id) {
			var is_active = img.src.indexOf("active1.png") >= 0;
			if (is_active) {
				img.src = img.src.replace("active1.png", "active0.png");
				$(img).attr('title', '<?php _e("درگاه غیر فعال است", "gravityformsdigidargah") ?>').attr('alt', '<?php _e("درگاه غیر فعال است", "gravityformsdigidargah") ?>');
			} else {
				img.src = img.src.replace("active0.png", "active1.png");
				$(img).attr('title', '<?php _e("درگاه فعال است", "gravityformsdigidargah") ?>').attr('alt', '<?php _e("درگاه فعال است", "gravityformsdigidargah") ?>');
			}
			var mysack = new sack(ajaxurl);
			mysack.execute = 1;
			mysack.method = 'POST';
			mysack.setVar("action", "gf_digidargah_update_feed_active");
			mysack.setVar("gf_digidargah_update_feed_active", "<?= wp_create_nonce("gf_digidargah_update_feed_active") ?>");
			mysack.setVar("feed_id", feed_id);
			mysack.setVar("is_active", is_active?0:1);
			mysack.onError = function(){
				alert('<?php _e("خطایی بروز کرده است. لطفا صفحه را رفرش کرده و مجددا تلاش نمایید. در صورت عدم رفع مشکل با پشتیبانی دیجی درگاه مکاتبه نمایید.", "gravityformsdigidargah") ?>');
			};
			mysack.runAJAX();
			return true;
		}
		</script>
		
		</div>
		</div>
		</div>
		</div>
		</div>
		<?php
	}

	public static function update_feed_active() {
		check_ajax_referer('gf_digidargah_update_feed_active', 'gf_digidargah_update_feed_active');
		$id = absint(rgpost('feed_id'));
		$feed = GF_digidargah_Database::get_feed($id);
		GF_digidargah_Database::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
	}

	private static function return_url($form_id, $entry_id) {

		$pageURL = GFCommon::is_ssl()?'https://':'http://';
		$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];

		$arr_params = array('id', 'entry', 'finalize');
		$pageURL    = esc_url(remove_query_arg($arr_params, $pageURL));

		$pageURL = str_replace('#038;', '&', add_query_arg(array(
			'id'    => $form_id,
			'entry' => $entry_id
		), $pageURL));

		return apply_filters('digidargah_return_url', apply_filters('gateway_return_url', $pageURL, $form_id, $entry_id, __CLASS__), $form_id, $entry_id, __CLASS__);
	}

	public static function get_order_total($form, $entry) {
		$total = GFCommon::get_order_total($form, $entry);
		$total = (!empty($total) && $total > 0)?$total:0;
		return apply_filters('digidargah_get_order_total', apply_filters('gateway_get_order_total', $total, $form, $entry), $form, $entry);
	}

	public static function payment_entry_detail($form_id, $entry) {

		$payment_gateway = rgar($entry, "payment_method");

		if (!empty($payment_gateway) && $payment_gateway == "digidargah") {

			do_action('gf_gateway_entry_detail');
			?>
            <hr/><strong><?php _e('اطلاعات تراکنش :', 'gravityformsdigidargah') ?></strong><br/><br/>
			<?php

			$payment_action = rgar($entry, "payment_action");
			$payment_status   = rgar($entry, "payment_status");
			$payment_amount   = rgar($entry, "payment_amount");

			if (empty($payment_amount)) {
				$form           = RGFormsModel::get_form_meta($form_id);
				$payment_amount = self::get_order_total($form, $entry);
			}

			$transaction_id = rgar($entry, "transaction_id");
			$payment_date   = rgar($entry, "payment_date");

			$date = new DateTime($payment_date);
			$tzb  = get_option('gmt_offset');
			$tzn  = abs($tzb) * 3600;
			$tzh  = intval(gmdate("H", $tzn));
			$tzm  = intval(gmdate("i", $tzn));

			if (intval($tzb) < 0)
				$date->sub(new DateInterval('P0DT' . $tzh . 'H' . $tzm . 'M'));
			else
				$date->add(new DateInterval('P0DT' . $tzh . 'H' . $tzm . 'M'));

			$payment_date = $date->format('Y-m-d H:i:s');

			if ($payment_status == 'paid')
				$payment_status_persian = __('موفق', 'gravityformsdigidargah');

			if ($payment_status == 'active')
				$payment_status_persian = __('موفق', 'gravityformsdigidargah');

			if ($payment_status == 'cancelled')
				$payment_status_persian = __('منصرف شده', 'gravityformsdigidargah');

			if ($payment_status == 'failed')
				$payment_status_persian = __('ناموفق', 'gravityformsdigidargah');

			if ($payment_status == 'processing')
				$payment_status_persian = __('معلق', 'gravityformsdigidargah');

			if (!strtolower(rgpost("save")) || RGForms::post("screen_mode") != "edit") {
				echo __('وضعیت پرداخت : ', 'gravityformsdigidargah') . $payment_status_persian . '<br/><br/>';
				echo __('تاریخ پرداخت : ', 'gravityformsdigidargah') . '<span style="">' . $payment_date . '</span><br/><br/>';
				echo __('مبلغ پرداختی : ', 'gravityformsdigidargah') . GFCommon::to_money($payment_amount, rgar($entry, "currency")) . '<br/><br/>';
				echo __('شماره تراکنش : ', 'gravityformsdigidargah') . $transaction_id . '<br/><br/>';
				echo __('درگاه پرداخت : دیجی درگاه', 'gravityformsdigidargah');
			} else {
				$payment_string = '';
				$payment_string .= '<select id="payment_status" name="payment_status">';
				$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status_persian . '</option>';

				if ($payment_action == 1) {
					if ($payment_status != "paid")
						$payment_string .= '<option value="paid">' . __('موفق', 'gravityformsdigidargah') . '</option>';
				}

				if ($payment_action == 2) {
					if ($payment_status != "active")
						$payment_string .= '<option value="active">' . __('موفق', 'gravityformsdigidargah') . '</option>';
				}

				if (!$payment_action) {

					if ($payment_status != "paid")
						$payment_string .= '<option value="paid">' . __('موفق', 'gravityformsdigidargah') . '</option>';

					if ($payment_status != "active")
						$payment_string .= '<option value="active">' . __('موفق', 'gravityformsdigidargah') . '</option>';
				}

				if ($payment_status != "failed")
					$payment_string .= '<option value="failed">' . __('ناموفق', 'gravityformsdigidargah') . '</option>';

				if ($payment_status != "cancelled")
					$payment_string .= '<option value="cancelled">' . __('منصرف شده', 'gravityformsdigidargah') . '</option>';

				if ($payment_status != "processing")
					$payment_string .= '<option value="processing">' . __('معلق', 'gravityformsdigidargah') . '</option>';

				$payment_string .= '</select>';

				echo __('وضعیت پرداخت :', 'gravityformsdigidargah') . $payment_string . '<br/><br/>';
				?>
				<div id="edit_payment_status_details" style="display:block">
				<table>
				<tr>
				<td><?php _e('تاریخ پرداخت :', 'gravityformsdigidargah') ?></td>
				<td><input type="text" id="payment_date" name="payment_date" value="<?= $payment_date ?>"></td>
				</tr>
				<tr>
				<td><?php _e('مبلغ پرداخت :', 'gravityformsdigidargah') ?></td>
				<td><input type="text" id="payment_amount" name="payment_amount" value="<?= $payment_amount ?>"></td>
				</tr>
				<tr>
				<td><?php _e('شماره تراکنش :', 'gravityformsdigidargah') ?></td>
				<td><input type="text" id="digidargah_transaction_id" name="digidargah_transaction_id" value="<?= $transaction_id ?>"></td>
				</tr>
				</table>
				<br/>
				</div>
				<?php
				echo __('درگاه پرداخت:دیجی درگاه (غیر قابل ویرایش)', 'gravityformsdigidargah');
			}

			echo '<br/>';
		}
	}

	public static function update_payment_entry($form, $entry_id) {

		check_admin_referer('gforms_save_entry', 'gforms_save_entry');
		do_action('gf_gateway_update_entry');
		$entry = GFAPI::get_entry($entry_id);
		$payment_gateway = rgar($entry, "payment_method");

		if (empty($payment_gateway)) return;
		if ($payment_gateway != "digidargah") return;

		$payment_status = rgpost("payment_status");
		if (empty($payment_status)) $payment_status = rgar($entry, "payment_status");

		$payment_amount = rgpost("payment_amount");
		$payment_transaction_id = rgpost("digidargah_transaction_id");
		$payment_date_Checker = $payment_date = rgpost("payment_date");

		list($date, $time) = explode(" ", $payment_date);
		list($Y, $m, $d) = explode("-", $date);
		list($H, $i, $s) = explode(":", $time);
		$miladi = GF_jalali_to_gregorian($Y, $m, $d);

		$date         = new DateTime("$miladi[0]-$miladi[1]-$miladi[2] $H:$i:$s");
		$payment_date = $date->format('Y-m-d H:i:s');

		if (empty($payment_date_Checker)) {
			if (!empty($entry["payment_date"]))
				$payment_date = $entry["payment_date"];
			else
				$payment_date = rgar($entry, "date_created");
		
		} else {
			$payment_date = date("Y-m-d H:i:s", strtotime($payment_date));
			$date         = new DateTime($payment_date);
			$tzb          = get_option('gmt_offset');
			$tzn          = abs($tzb) * 3600;
			$tzh          = intval(gmdate("H", $tzn));
			$tzm          = intval(gmdate("i", $tzn));
			if (intval($tzb) < 0)
				$date->add(new DateInterval('P0DT' . $tzh . 'H' . $tzm . 'M'));
			else
				$date->sub(new DateInterval('P0DT' . $tzh . 'H' . $tzm . 'M'));
			
			$payment_date = $date->format('Y-m-d H:i:s');
		}

		global $current_user;
		$user_id   = 0;
		$user_name = __("مهمان", 'gravityformsdigidargah');
		if ($current_user && $user_data = get_userdata($current_user->ID)) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry["payment_status"] = $payment_status;
		$entry["payment_amount"] = $payment_amount;
		$entry["payment_date"]   = $payment_date;
		$entry["transaction_id"] = $payment_transaction_id;
		if ($payment_status == 'paid' || $payment_status == 'active') $entry["is_fulfilled"] = 1;
		else $entry["is_fulfilled"] = 0;
		
		GFAPI::update_entry($entry);

		$new_status = '';
		switch (rgar($entry, "payment_status")) {
			case "active" : $new_status = __('موفق', 'gravityformsdigidargah'); break;
			case "paid" : $new_status = __('موفق', 'gravityformsdigidargah'); break;
			case "cancelled" : $new_status = __('منصرف شده', 'gravityformsdigidargah'); break;
			case "failed" : $new_status = __('ناموفق', 'gravityformsdigidargah'); break;
			case "processing" : $new_status = __('معلق', 'gravityformsdigidargah'); break;
		}

		RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("اطلاعات تراکنش به صورت دستی ویرایش شد. <br> وضعیت : %s <br> مبلغ : %s <br> کد رهگیری : %s <br> تاریخ : %s", "gravityformsdigidargah"), $new_status, GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $payment_transaction_id, $entry["payment_date"]));
	}

	public static function settings_page() {

		if (isset($_POST["gf_digidargah_submit"])) {

			check_admin_referer("update", "gf_digidargah_update");
			$settings = array("api_key" => rgpost('gf_digidargah_api_key'), "pay_currency" => rgpost('gf_digidargah_pay_currency'), "gate_name" => rgpost('gf_digidargah_gate_name'));
			update_option("gf_digidargah_settings", array_map('sanitize_text_field', $settings));
			
			if (isset($_POST["gf_digidargah_configured"]))
				update_option("gf_digidargah_configured", sanitize_text_field($_POST["gf_digidargah_configured"]));
			else
				delete_option("gf_digidargah_configured");
		
		} else
			$settings = get_option("gf_digidargah_settings");

		if (!empty($_POST))
			echo '<div class="updated fade">' . __("تنظیم ها ذخیره شدند.", "gravityformsdigidargah") . '</div>';
				
		else if (isset($_GET['subview']) && $_GET['subview'] == 'gf_digidargah' && isset($_GET['updated']))
			echo '<div class="updated fade">' . __("تنظیم ها ذخیره شدند.", "gravityformsdigidargah") . '</div>';
			
		echo self::$markups;
		
		?>
		
        <div class="gform-settings__content">
		<form class="gform_settings_form" method="post">
		
		<?php wp_nonce_field("update", "gf_digidargah_update") ?>
		
		<fieldset class="gform-settings-panel gform-settings-panel--with-title">
		<legend class="gform-settings-panel__title gform-settings-panel__title--header"><?php _e("تنظیم های دیجی درگاه", "gravityformsdigidargah") ?></legend>

		<div class="gform-settings-panel__content">
		
		<div class="gform-settings-description gform-kitchen-sink"><?php _e("با تیک زدن گزینه زیر، افزونه دیجی درگاه برای گرویتی فرم فعال می شود.", "gravityformsdigidargah"); ?></div>
		
		<div class="gform-settings-field gform-settings-field__checkbox">
		<span class="gform-settings-input__container">
		<div id="gform-settings-checkbox-choice-enabled" class="gform-settings-choice">
		<input type="checkbox" name="gf_digidargah_configured" id="gf_digidargah_configured" <?= get_option("gf_digidargah_configured")?"checked='checked'":"" ?>>
		<label for="gf_digidargah_configured"><span><?php _e("فعال سازی", "gravityformsdigidargah"); ?></span></label>
		</div>
		</span>
		</div>
		
		<div class="hr-divider"></div>
		
		<?php
		$gateway_title = __("دیجی درگاه", "gravityformsdigidargah");
		if (sanitize_text_field(rgar($settings, 'gate_name'))) $gateway_title = sanitize_text_field($settings["gate_name"]);
		?>

		<div class="gform-settings-field gform-settings-field__text">
		<div class="gform-settings-field__header">
		<label class="gform-settings-label" for="gf_digidargah_gate_name"><?php _e("عنوان نمایشی درگاه", "gravityformsdigidargah"); ?></label>
		</div>
		<span class="gform-settings-input__container">
		<input type="text" name="gf_digidargah_gate_name" id="gf_digidargah_gate_name" value="<?= $gateway_title; ?>">
		</span>
		</div>
		
		<div class="hr-divider"></div>
		
		<div class="gform-settings-field gform-settings-field__text">
		<div class="gform-settings-field__header">
		<label class="gform-settings-label" for="gf_digidargah_api_key"><?php _e("کلید API", "gravityformsdigidargah"); ?></label>
		</div>
		<span class="gform-settings-input__container">
		<input type="text" id="gf_digidargah_api_key" name="gf_digidargah_api_key" value="<?= sanitize_text_field(rgar($settings, 'api_key')) ?>">
		</span>
		</div>
		
		<div class="hr-divider"></div>
		
		<div class="gform-settings-field gform-settings-field__text">
		<div class="gform-settings-field__header">
		<label class="gform-settings-label" for="gf_digidargah_pay_currency"><?php _e("ارزهای قابل انتخاب توسط مشتری", "gravityformsdigidargah"); ?></label>
		</div>
		<span class="gform-settings-input__container">
		<input type="text" id="gf_digidargah_pay_currency" name="gf_digidargah_pay_currency" value="<?= sanitize_text_field(rgar($settings, 'pay_currency')) ?>">
		</span>
		</div>
		
		<div class="gform-settings-description gform-kitchen-sink"><?php _e("با خالی گذاشتن این فیلد، مشتری امکان پرداخت از طریق تمام ارزهای فعال در مجموعه را خواهد داشت. در صورت تمایل به انتخاب بیش از یک ارز، لطفا آنها را از طریق خط تیره از هم جدا نمایید. ( مثال : bitcoin-ethereum-bnb )", "gravityformsdigidargah"); ?></div>

		</div>
		</fieldset>

		<div class="gform-settings-save-container">
		<button type="submit" id="gf_digidargah_submit" name="gf_digidargah_submit" class="primary button large"><?php _e("بروز رسانی", "gravityformsdigidargah") ?></button>
		</div>

		</form>
		</div>
		
		<div class="hr-divider"></div>
		
		<?php
	}
	
	public static function get_setting($field) {
		$settings = get_option("gf_digidargah_settings");
		$field_value = isset($settings[$field])?$settings[$field]:'';
		if ($field == 'gate_name' and $field_value == '') $field_value = __('دیجی درگاه', 'gravityformsdigidargah');
		return trim($field_value);
	}

	private static function config_page() {

		wp_enqueue_style('gform_admin_digidargah', GFCommon::get_base_url() . '/assets/css/dist/admin.min.css');
		wp_enqueue_style('gform_settings_digidargah', GFCommon::get_base_url() . '/assets/css/dist/settings.min.css');
		wp_print_styles(array('jquery-ui-styles', 'gform_admin_digidargah', 'wp-pointer'));
		
		echo self::$markups;
		
		?>
		
		<div class="wrap gforms_edit_form gforms_form_settings_wrap gf_browser_gecko">
		
		<header class="gform-settings-header">
		<div class="gform-settings__wrapper">
		<img src="<?= GFCommon::get_base_url(); ?>/images/logos/gravity-logo-white.svg" alt="Gravity Forms" width="265">
		<div class="gform-settings-header_buttons"></div>
		</div>
		</header>
		
		<!-- WP appends notices to the first H tag, so this is here to capture admin notices. -->
		<div id="gf-admin-notices-wrapper"><h2 class="gf-notice-container"></h2></div>
		
		<div class="gform-settings__wrapper gform-settings__wrapper--full">
		<div class="gform-settings__content">
		<div class="gform-settings-panel__content form-list">
		<div class="wrap gforms_edit_form gf_browser_gecko">
		<?php
		
		$id = !rgempty("digidargah_setting_id")?rgpost("digidargah_setting_id"):absint(rgget("id"));
		$config = empty($id)?array("meta" => array(), "is_active" => true):GF_digidargah_Database::get_feed($id);
		
		$get_feeds = GF_digidargah_Database::get_feeds();
		$_get_form_id = rgget('fid')?rgget('fid'):(!empty($config["form_id"])?$config["form_id"]:'');
		
		$form_name = '';
		foreach ($get_feeds as $get_feed) {
			if ($get_feed['id'] == $id) $form_name = $get_feed['form_title'];
		}
		
		if ($form_name == '' && $_get_form_id > 0) {
			global $wpdb;
			$form_table_name = RGFormsModel::get_form_table_name();
			$sql = $wpdb->prepare("select title from $form_table_name where id = %d", $_get_form_id);
			$result = $wpdb->get_results($sql, ARRAY_A);
			if (!empty($result)) {
				$result = $result[0];
				$form_name = $result['title'];
			}
		}
		
		?>
		
		<div class="form-list-head">
		<h2><?php
		$h2str = "مدیریت فید";
		if ($form_name != '') $h2str .= " :: فرم " . $form_name;
		if ($id > 0) $h2str .= " :: فید شماره " . $id;
		_e($h2str, "gravityformsdigidargah");
		?>
		</h2>
		<a class="button gform-add-new-form add-new-h2" href="admin.php?page=gf_digidargah"><?php _e("برگشت به لیست", "gravityformsdigidargah") ?></a>
		</div>
		
		<div class="hr-divider"></div>
		
		<?php
		
		if (!rgempty("gf_digidargah_submit")) {
			
			check_admin_referer("update", "gf_digidargah_feed");
			
			$config["form_id"] = absint(rgpost("gf_digidargah_form"));
			
			$config["meta"]["type"] = rgpost("gf_digidargah_type");
			$config["meta"]["addon"] = rgpost("gf_digidargah_addon");
			$config["meta"]["update_post_action1"] = rgpost('gf_digidargah_update_action1');
			$config["meta"]["update_post_action2"] = rgpost('gf_digidargah_update_action2');			
			$config["meta"]["desc_pm"] = rgpost("gf_digidargah_desc_pm");
			
			$safe_data = array();
			foreach ($config["meta"] as $key => $val) {
				if (!is_array($val)) $safe_data[$key] = sanitize_text_field($val);
				else $safe_data[ $key ] = array_map('sanitize_text_field', $val);
			}
			
			$config["meta"] = $safe_data;
			$config = apply_filters('gform_gateway_save_config', $config);
			$config = apply_filters('gform_digidargah_save_config', $config);
			
			$id = GF_digidargah_Database::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
			
			if (!headers_sent()) {
				wp_redirect(admin_url('admin.php?page=gf_digidargah&view=edit&id=' . $id . '&updated=true'));
				exit;
				
			} else {
				echo "<script>window.onload = function(){top.location.href = '" . admin_url('admin.php?page=gf_digidargah&view=edit&id=' . $id . '&updated=true') . "';};</script>";
				exit;
			}
			
			?>
			
			<div class="updated fade"><?= __("داده ها با موفقیت ثبت شدند.", "gravityformsdigidargah"); ?></div>
			

			<?php
		}
		
		$_get_form_id = rgget('fid')?rgget('fid'):(!empty($config["form_id"])?$config["form_id"]:'');
		
		$form = array();
		if (!empty($_get_form_id)) $form = RGFormsModel::get_form_meta($_get_form_id);
		
		if (rgget('updated') == 'true') {
			$id = empty($id) && isset($_GET['id'])?rgget('id'):$id;
			$id = absint($id);
			?>
			<div class="updated fade"><?= __("داده ها با موفقیت ثبت شدند.", "gravityformsdigidargah"); ?></div>
			<?php
		}
		
		$has_product = false;
		if (isset($form["fields"])) {
			foreach ($form["fields"] as $field) {
				$shipping_field = GFAPI::get_fields_by_type($form, array('shipping'));
				if ($field["type"] == "product" || !empty($shipping_field)) {
					$has_product = true;
					break;
				}
			}
		} elseif (empty($_get_form_id))
			$has_product = true;
		
		if (empty($has_product) || !$has_product) { ?>
			<div class="updated">
			<?php _e("فرم انتخاب شده فیلد قیمت گذاری ندارد !", "gravityformsdigidargah") ?>
			</div>
		<?php } ?>
		
		<div id="gform_tab_group" class="gform_tab_group vertical_tabs">
		
		<div id="gform_tab_container_<?= $_get_form_id?$_get_form_id:1 ?>" class="gform_tab_container">
		<div class="gform_tab_content" id="tab_<?= !empty($current_tab)?$current_tab:'' ?>">
		
		<div id="form_settings" class="gform-settings__content" style="<?= ((rgget('id') || rgget('fid')) & !empty($has_product))?'display:none !important':''; ?>">
		
		<form class="gform_settings_form" method="post">
		<input type="hidden" name="digidargah_setting_id" value="<?= $id ?>"/>
		<?php wp_nonce_field("update", "gf_digidargah_feed") ?>
		
		<fieldset class="gform-settings-panel gform-settings-panel--with-title">
		<legend class="gform-settings-panel__title gform-settings-panel__title--header"><?php _e("انتخاب فرم", "gravityformsdigidargah") ?></legend>
		<div class="gform-settings-panel__content">
		<div class="gform-settings-field gform-settings-field__text">
		<span class="gform-settings-input__container">
		<select id="gf_digidargah_form" name="gf_digidargah_form" class="select2-hidden-accessible">
		<option value=""><?php _e("یک فرم را انتخاب نمایید", "gravityformsdigidargah"); ?> </option>
		<?php
		$available_forms = GF_digidargah_Database::get_available_forms();
		foreach ($available_forms as $current_form) {
			$selected = absint($current_form->id) == $_get_form_id?'selected="selected"':'';
			?>
			<option value="<?= absint($current_form->id) ?>" <?= $selected; ?>><?= esc_html($current_form->title) ?></option>
			<?php
		}
		?>
		</select>
		</span>
		</div>
		</div>
		</fieldset>
		
		</div>
		
		<?php if (!empty($has_product)) { ?>
			
			<div <?= empty($_get_form_id)?"style='display:none;'":"" ?>>
			
			<fieldset class="gform-settings-panel gform-settings-panel--with-title">
			
			<div class="gform-settings-panel__content">
		
			<div class="gform-settings-description gform-kitchen-sink"><?php _e('در صورتی که تیک این گزینه را فعال نمایید عملیات ثبت نام که توسط افزونه User Registration انجام می شود تنها برای پرداخت های موفق عمل خواهد کرد.'); ?></div>
			<br>
			<div class="gform-settings-field gform-settings-field__checkbox">
			<span class="gform-settings-input__container">
			<div id="gform-settings-checkbox-choice-enabled" class="gform-settings-choice">
			<input type="checkbox" value="subscription" name="gf_digidargah_type" id="gf_digidargah_type_subscription" <?= rgar($config['meta'], 'type') == "subscription"?"checked='checked'":"" ?>>
			<label for="gf_digidargah_type"><span><?php _e("وضعیت ثبت نام", "gravityformsdigidargah"); ?></span></label>
			</div>
			</span>
			</div>

			<div class="hr-divider"></div>
			
			<div class="gform-settings-field gform-settings-field__text">
			<div class="gform-settings-field__header">
			<label class="gform-settings-label" for="gf_digidargah_desc_pm"><?php _e("پیوست داده :: فیلد اول <small> ( اطلاعاتی که در هنگام ارسال درخواست به درگاه، همراه با تراکنش در سایت دیجی درگاه ذخیره می شود. شورت کد ها : {form_id} {form_title} {entry_id} ) </small>", "gravityformsdigidargah"); ?></label>
			</div>
			<span class="gform-settings-input__container">
			<input type="text" id="gf_digidargah_desc_pm" name="gf_digidargah_desc_pm" value="<?= rgar($config["meta"], "desc_pm") ?>">
			</span>
			</div>

			<div class="hr-divider"></div>
			
			<div class="gform-settings-field gform-settings-field__text">
			<div class="gform-settings-field__header">
			<label class="gform-settings-label" for="digidargah_customer_field_desc"><?php _e("پیوست داده :: فیلد دوم <small> اطلاعاتی که در هنگام ارسال درخواست به درگاه، همراه با تراکنش در سایت دیجی درگاه ذخیره می شود </small>", "gravityformsdigidargah"); ?></label>
			</div>
			<span class="gform-settings-input__container">
			<?php
				
			$fields = array();
			if (is_array($form["fields"])) {
				foreach ($form["fields"] as $field) {
					if (isset($field["inputs"]) && is_array($field["inputs"])) {
						foreach ($field["inputs"] as $input)
							$fields[] = array($input["id"], GFCommon::get_label($field, $input["id"]));
					} else if (!rgar($field, 'displayonly'))
						$fields[] = array($field["id"], GFCommon::get_label($field));
				}
			}

			$selected_field = !empty($config["meta"]["customer_fields_desc"])?$config["meta"]["customer_fields_desc"]:'';

			$str = "<select name='digidargah_customer_field_desc' id='$selected_field'><option value=''></option>";
			if (is_array($fields)) {
				foreach ($fields as $field) {
					$field_id = $field[0];
					$field_label = esc_html(GFCommon::truncate_middle($field[1], 40));
					$selected = $field_id == $selected_field?"selected='selected'":"";
					$str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . "</option>";
				}
			}
			$str .= "</select>";
			echo $str;
			
			?>
			</span>
			</div>

			<div class="hr-divider"></div>
						
			<div class="gform-settings-field gform-settings-field__text">
			<div class="gform-settings-field__header">
			<label class="gform-settings-label" for="gf_digidargah_update_action1"><?php _e("وضعیت پست پس از پرداخت", "gravityformsdigidargah"); ?></label>
			</div>
			<span class="gform-settings-input__container">
			<select id="gf_digidargah_update_action1" name="gf_digidargah_update_action1">
			<option value="default" <?= rgar($config["meta"], "update_post_action1") == "default"?"selected='selected'":"" ?>><?php _e("وضعیت پیشفرض فرم", "gravityformsdigidargah") ?></option>
			<option value="publish" <?= rgar($config["meta"], "update_post_action1") == "publish"?"selected='selected'":"" ?>><?php _e("منتشر شده", "gravityformsdigidargah") ?></option>
			<option value="draft" <?= rgar($config["meta"], "update_post_action1") == "draft"?"selected='selected'":"" ?>><?php _e("پیشنویس", "gravityformsdigidargah") ?></option>
			<option value="pending" <?= rgar($config["meta"], "update_post_action1") == "pending"?"selected='selected'":"" ?>><?php _e("در انتظار بررسی", "gravityformsdigidargah") ?></option>
			<option value="private" <?= rgar($config["meta"], "update_post_action1") == "private"?"selected='selected'":"" ?>><?php _e("خصوصی", "gravityformsdigidargah") ?></option>
			</select>
			</span>
			</div>

			<div class="hr-divider"></div>
						
			<div class="gform-settings-field gform-settings-field__text">
			<div class="gform-settings-field__header">
			<label class="gform-settings-label" for="gf_digidargah_update_action2"><?php _e("وضعیت پست قبل از پرداخت", "gravityformsdigidargah"); ?></label>
			</div>
			<span class="gform-settings-input__container">
			<select id="gf_digidargah_update_action2" name="gf_digidargah_update_action2">
			<option value="dont" <?= rgar($config["meta"], "update_post_action2")=="dont"?"selected='selected'":"" ?>><?php _e("عدم ایجاد پست", "gravityformsdigidargah") ?></option>
			<option value="default" <?= rgar($config["meta"], "update_post_action2") == "default"?"selected='selected'":"" ?>><?php _e("وضعیت پیشفرض فرم", "gravityformsdigidargah") ?></option>
			<option value="publish" <?= rgar($config["meta"], "update_post_action2") == "publish"?"selected='selected'":"" ?>><?php _e("منتشر شده", "gravityformsdigidargah") ?></option>
			<option value="draft" <?= rgar($config["meta"], "update_post_action2") == "draft"?"selected='selected'":"" ?>><?php _e("پیشنویس", "gravityformsdigidargah") ?></option>
			<option value="pending" <?= rgar($config["meta"], "update_post_action2") == "pending"?"selected='selected'":"" ?>><?php _e("در انتظار بررسی", "gravityformsdigidargah") ?></option>
			<option value="private" <?= rgar($config["meta"], "update_post_action2") == "private"?"selected='selected'":"" ?>><?php _e("خصوصی", "gravityformsdigidargah") ?></option>
			</select>
			</span>
			</div>
			
			<div class="hr-divider"></div>
			
			<div class="gform-settings-description gform-kitchen-sink"><?php _e('برخی از افزونه های گرویتی فرم دارای متد add_delayed_payment_support هستند. در صورتی که می خواهید این افزونه ها تنها در صورت تراکنش موفق عمل کنند تیک این گزینه را فعال نمایید.'); ?></div>
			<br>
			<div class="gform-settings-field gform-settings-field__checkbox">
			<span class="gform-settings-input__container">
			<div id="gform-settings-checkbox-choice-enabled" class="gform-settings-choice">
			<input type="checkbox" name="gf_digidargah_addon" id="gf_digidargah_addon_true" value="true" <?= rgar($config['meta'], 'addon') == "true"?"checked='checked'":"" ?>/>
			<label for="gf_digidargah_addon"><span><?php _e("سازگاری با افزونه ها", "gravityformsdigidargah"); ?></span></label>
			</div>
			</span>
			</div>

			<div class="hr-divider"></div>
			
			<?php
			
			do_action('gform_gateway_config', $config, $form);
			do_action('gform_digidargah_config', $config, $form);
			
			//add code here if you need conditional form-------------
			//..............
			//add code here if you need conditional form-------------
			
			?>
			<input type="hidden" id="gf_digidargah_conditional_enabled" name="gf_digidargah_conditional_enabled" value="0"/>
			
			</div>
			</fieldset>
			
			<div class="gform-settings-save-container">
			<input type="submit"  name="gf_digidargah_submit" class="primary button large" value="<?php _e("ثبت", "gravityformsdigidargah") ?>">
			</div>
			
			</div>
					
		<?php } ?>
		</form>
		</div>
		</div>
		</div>
		</div>
		</div>
		
		</div>
		</div>
		</div>
		
		<script>
		var form = [];
		form = <?= !empty($form)?GFCommon::json_encode($form):GFCommon::json_encode(array()) ?>;
		$(document).ready(function(){				
			$('#gf_digidargah_form').change(function(){
				var form_id = $(this).val();
				document.location = "?page=gf_digidargah&view=edit&fid="+form_id;
			});
		});				
		</script>
		
		<?php
	}

	public static function request($confirmation, $form, $entry, $ajax) {

		do_action('gf_gateway_request_1', $confirmation, $form, $entry, $ajax);
		do_action('gf_digidargah_request_1', $confirmation, $form, $entry, $ajax);
		
		if (apply_filters('gf_digidargah_request_return', apply_filters('gf_gateway_request_return', false, $confirmation, $form, $entry, $ajax), $confirmation, $form, $entry, $ajax)) return $confirmation;
		
		global $current_user;		
		$user_id   = 0;
		$user_name = __('مهمان', 'gravityformsdigidargah');
		$custom = $confirmation == 'custom';

		if ($current_user && $user_data = get_userdata($current_user->ID)) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry_id = $entry['id'];

		if (!$custom) {

			$config = self::get_active_config($form);
			if (RGForms::post("gform_submit") != $form['id']) return $confirmation;
			if (empty($config)) return $confirmation;

			gform_update_meta($entry['id'], 'digidargah_feed_id', $config['id']);
			gform_update_meta($entry['id'], 'payment_type', 'form');
			gform_update_meta($entry['id'], 'payment_gateway', self::get_setting('gate_name'));

			switch ($config["meta"]["type"]) {
				case "subscription" : $payment_action = 2; break;
				default : $payment_action = 1; break;
			}

			if (GFCommon::has_post_field($form["fields"])) {
				if (!empty($config["meta"]["update_post_action2"])) {
					if ($config["meta"]["update_post_action2"] != 'dont') {
						if ($config["meta"]["update_post_action2"] != 'default')
							$form['poststatus'] = $config["meta"]["update_post_action2"];
					} else
						$dont_create = true;
				}
				if (empty($dont_create))
					RGFormsModel::create_post($form, $entry);
			}

			$amount = self::get_order_total($form, $entry);
			$amount = apply_filters("gform_form_gateway_price_{$form['id']}", apply_filters("gform_form_gateway_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_form_digidargah_price_{$form['id']}", apply_filters("gform_form_digidargah_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_gateway_price_{$form['id']}", apply_filters("gform_gateway_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_digidargah_price_{$form['id']}", apply_filters("gform_digidargah_price", $amount, $form, $entry), $form, $entry);

			if (empty($amount) || !$amount || $amount == 0) {
				unset($entry["payment_status"], $entry["payment_method"], $entry["is_fulfilled"], $entry["payment_action"], $entry["payment_amount"], $entry["payment_date"]);
				$entry["payment_method"] = "digidargah";
				GFAPI::update_entry($entry);

				return self::redirect_confirmation(add_query_arg(array('finalize' => 'true'), self::return_url($form['id'], $entry['id'])), $ajax);
			} else {

				$Desc1 = '';
				if (!empty($config["meta"]["desc_pm"])) {
					$Desc1 = str_replace(array('{entry_id}', '{form_title}', '{form_id}'), array($entry['id'], $form['title'], $form['id']), $config["meta"]["desc_pm"]);
				}
				
				$Desc2 = '';
				if (!empty($config["meta"]["customer_fields_desc"])) {
					if (rgpost('input_' . str_replace(".", "_", $config["meta"]["customer_fields_desc"])))
						$Desc2 = rgpost('input_' . str_replace(".", "_", $config["meta"]["customer_fields_desc"]));
				}

				if (!empty($Desc1) && !empty($Desc2)) $description = $Desc1 . ' - ' . $Desc2;
				else if (!empty($Desc1) && empty($Desc2)) $description = $Desc1;
				else if (!empty($Desc2) && empty($Desc1)) $description = $Desc2;
				else $description = ' ';
				
				$description = sanitize_text_field($description);
			}

		}
		
		else {

			$amount = gform_get_meta(rgar($entry, 'id'), 'digidargah_part_price_' . $form['id']);
			$amount = apply_filters("gform_custom_gateway_price_{$form['id']}", apply_filters("gform_custom_gateway_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_custom_digidargah_price_{$form['id']}", apply_filters("gform_custom_digidargah_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_gateway_price_{$form['id']}", apply_filters("gform_gateway_price", $amount, $form, $entry), $form, $entry);
			$amount = apply_filters("gform_digidargah_price_{$form['id']}", apply_filters("gform_digidargah_price", $amount, $form, $entry), $form, $entry);

			$description = gform_get_meta(rgar($entry, 'id'), 'digidargah_part_desc_' . $form['id']);
			$description = apply_filters('gform_digidargah_gateway_desc_', apply_filters('gform_custom_gateway_desc_', $description, $form, $entry), $form, $entry);
			$Paymenter = gform_get_meta(rgar($entry, 'id'), 'digidargah_part_name_' . $form['id']);
			$entry_id = GFAPI::add_entry($entry);
			$entry = GFAPI::get_entry($entry_id);
			do_action('gf_gateway_request_add_entry', $confirmation, $form, $entry, $ajax);
			do_action('gf_digidargah_request_add_entry', $confirmation, $form, $entry, $ajax);
			
			gform_update_meta($entry_id, 'payment_gateway', self::get_setting('gate_name'));
			gform_update_meta($entry_id, 'payment_type', 'custom');
		}
		
		unset($entry["payment_status"]);
		unset($entry["payment_method"]);
		unset($entry["is_fulfilled"]);
		unset($entry["payment_action"]);
		unset($entry["payment_amount"]);
		unset($entry["payment_date"]);
		unset($entry["transaction_id"]);
		$entry["payment_status"] = "processing";
		$entry["payment_method"] = "digidargah";
		$entry["is_fulfilled"]   = 0;
		
		if (!empty($payment_action)) $entry["payment_action"] = $payment_action;
		
		GFAPI::update_entry($entry);
		$entry = GFAPI::get_entry($entry_id);
		
		do_action('gf_gateway_request_2', $confirmation, $form, $entry, $ajax);
		do_action('gf_digidargah_request_2', $confirmation, $form, $entry, $ajax);
		
		$params = array(
			'api_key' => self::get_setting('api_key'),
			'amount_value' => $amount,
			'amount_currency' => $entry["currency"],
			'pay_currency' => self::get_setting('pay_currency'),
			'order_id' => $entry_id,
			'desc' => 'پرداخت برای فرم ' . $form['title'] . ' :: ' . $description,
			'respond_type' => 'link',
			'iframe_style' => '',
			'callback' => self::return_url($form['id'], $entry_id)
		);

		$url = 'https://digidargah.com/action/ws/request/create';
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERAGENT => $_SERVER["HTTP_USER_AGENT"],
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => json_encode($params),
		]);
		$response = curl_exec($curl);
		curl_close($curl);
		$result = json_decode($response);
			
		if ($result->status != 'success') $message = $result->respond;
		else {
			$entry['transaction_id'] = $result->request_id;
			GFAPI::update_entry($entry);
			$entry = GFAPI::get_entry($entry_id);
			return self::redirect_confirmation($result->respond, $ajax);
		}
		
		$confirmation = sprintf(__('درگاه پرداخت با خطا مواجه شد. <br>%s', "gravityformsdigidargah"), $message);

		$entry = GFGFAPI::get_entry($entry_id);
		$entry['payment_status'] = 'failed';
		GFAPI::update_entry($entry);

		RGFormsModel::add_note($entry_id, $user_id, $user_name, sprintf(__('درگاه پرداخت با خطا مواجه شد. %s', "gravityformsdigidargah"), $message));

		if (!$custom) {
			$notifications = GFCommon::get_notifications_to_send('form_submission', $form, $entry);
			$_notifications = array();
			foreach((array) $notifications as $notification ) {
				$logic = rgar($notification, 'conditionalLogic');
				$rules = rgar($logic, 'rules');
				if (empty($rules)) continue;
				$fieldIds = wp_list_pluck($rules, 'fieldId');
				if (in_array('payment_status', $fieldIds)) $_notifications[] = $notification;
			}

			if (!empty($_notifications)){
				$_notifications = wp_list_pluck($_notifications, 'id');
				GFCommon::send_notifications($_notifications, $form, $entry);
			}
		}

		$default_anchor = 0;
		$anchor = gf_apply_filters('gform_confirmation_anchor', $form['id'], $default_anchor)?"<a id='gf_{$form['id']}' name='gf_{$form['id']}' class='gform_anchor' ></a>":'';
		$nl2br = !empty($form['confirmation']) && rgar($form['confirmation'], 'disableAutoformat')?false:true;
		$cssClass = rgar($form, 'cssClass');

		return $confirmation = empty($confirmation)?"{$anchor} ":"{$anchor}<div id='gform_confirmation_wrapper_{$form['id']}' class='gform_confirmation_wrapper {$cssClass}'><div id='gform_confirmation_message_{$form['id']}' class='gform_confirmation_message_{$form['id']} gform_confirmation_message'>" . GFCommon::replace_variables($confirmation, $form, $entry, false, true, $nl2br) . '</div></div>';
	}

	public static function verify() {
		
		if (apply_filters('gf_gateway_digidargah_return', apply_filters('gf_gateway_verify_return', false))) return;
		if (!self::is_gravityforms_supported()) return;
		if (empty($_GET['id']) || empty($_GET['entry']) || !is_numeric(rgget('id')) || !is_numeric(rgget('entry'))) return;

		$form_id  = $_GET['id'];
		$entry_id = $_GET['entry'];
		$entry = GFAPI::get_entry($entry_id);
		
		if (is_wp_error($entry)) return;

		if (isset($entry["payment_method"]) && $entry["payment_method"] == 'digidargah') {
			
			$form = RGFormsModel::get_form_meta($form_id);
			$payment_type = gform_get_meta($entry["id"], 'payment_type');
			gform_delete_meta($entry['id'], 'payment_type');

			if ($payment_type != 'custom') {
				$config = self::get_config_by_entry($entry);
				if (empty($config)) return;
			} else
				$config = apply_filters('gf_digidargah_config', apply_filters('gf_gateway_config', array(), $form, $entry), $form, $entry);

			global $current_user;
			$user_id   = 0;
			$user_name = __("مهمان", "gravityformsdigidargah");
			if ($current_user && $user_data = get_userdata($current_user->ID)) {
				$user_id   = $current_user->ID;
				$user_name = $user_data->display_name;
			}

			$payment_action = 1;
			if (!empty($config["meta"]["type"]) && $config["meta"]["type"] == 'subscription')
				$payment_action = 2;

			if ($payment_type == 'custom')
				$amount = $total = gform_get_meta($entry["id"], 'digidargah_part_price_' . $form_id);
			else
				$amount = $total = self::get_order_total($form, $entry);
				
			$total_money = GFCommon::to_money($total, $entry["currency"]);
						
			$api_key = self::get_setting('api_key');
			$entry_id = $entry['id'];
			$transaction_id = $entry['transaction_id'];
			
			$params = array(
				'api_key' => $api_key,
				'order_id' => $entry_id,
				'request_id' => $transaction_id
			);
			
			$url = 'https://digidargah.com/action/ws/request/status';
			$curl = curl_init();
			curl_setopt_array($curl, [
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_TIMEOUT => 60,
				CURLOPT_USERAGENT => $_SERVER["HTTP_USER_AGENT"],
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => json_encode($params),
			]);
			$response = curl_exec($curl);
			curl_close($curl);
			$result = json_decode($response);

			if ($result->status != 'success') {
				$message = $result->respond;
				$status  = 'failed';
			} else {
				$message = '';
				$status  = 'completed';
			}
			
			//----------------------------------------------------------------------------------------
			$entry["payment_date"] = gmdate("Y-m-d H:i:s");
			$entry["transaction_id"] = $transaction_id;
			$entry["payment_action"] = $payment_action;

			if ($status == 'completed') {

				$entry["is_fulfilled"]   = 1;
				$entry["payment_amount"] = $total;

				if ($payment_action == 2) {
					$entry["payment_status"] = "active";
					RGFormsModel::add_note($entry["id"], $user_id, $user_name, __("تغییر اطلاعات فیلدها فقط در پیام ورودی اعمال می شود و در وضعیت کاربر تاثیری نخواهد داشت.", "gravityformsdigidargah"));
				} else
					$entry["payment_status"] = "paid";

				$note = sprintf(__('وضعیت پرداخت : موفق <br> مبلغ پرداختی : %s <br> کد تراکنش : %s', "gravityformsdigidargah"), $total_money, $transaction_id);

				GFAPI::update_entry($entry);

				if (apply_filters('gf_digidargah_post', apply_filters('gf_gateway_post', ($payment_type != 'custom'), $form, $entry), $form, $entry)) {

					$has_post = GFCommon::has_post_field($form["fields"])?true:false;

					if (!empty($config["meta"]["update_post_action1"]) && $config["meta"]["update_post_action1"] != 'default') 
						$new_status = $config["meta"]["update_post_action1"];
					else
						$new_status = rgar($form, 'poststatus');

					if (empty($entry["post_id"]) && $has_post) {
						$form['poststatus'] = $new_status;
						RGFormsModel::create_post($form, $entry);
						$entry = GFAPI::get_entry($entry_id);
					}

					if (!empty($entry["post_id"]) && $has_post) {
						$post = get_post($entry["post_id"]);
						if (is_object($post)) {
							if ($new_status != $post->post_status) {
								$post->post_status = $new_status;
								wp_update_post($post);
							}
						}
					}
				}

				$user_registration_slug = apply_filters('gf_user_registration_slug', 'gravityformsuserregistration');
				$paypal_config          = array('meta' => array());
				if (!empty($config["meta"]["addon"]) && $config["meta"]["addon"] == 'true') {
					if (class_exists('GFAddon') && method_exists('GFAddon', 'get_registered_addons')) {
						$addons = GFAddon::get_registered_addons();
						foreach ((array) $addons as $addon) {
							if (is_callable(array($addon, 'get_instance'))) {
								$addon = call_user_func(array($addon, 'get_instance'));
								if (is_object($addon) && method_exists($addon, 'get_slug')) {
									$slug = $addon->get_slug();
									if ($slug != $user_registration_slug)
										$paypal_config['meta'][ 'delay_' . $slug ] = true;
								}
							}
						}
					}
				}
				
				if (!empty($config["meta"]["type"]) && $config["meta"]["type"] == "subscription")
					$paypal_config['meta'][ 'delay_' . $user_registration_slug ] = true;

				do_action("gform_digidargah_fulfillment", $entry, $config, $transaction_id, $total);
				do_action("gform_gateway_fulfillment", $entry, $config, $transaction_id, $total);
				do_action("gform_paypal_fulfillment", $entry, $paypal_config, $transaction_id, $total);
			
			}
			
			else if ($status == 'cancelled') {
				$entry["payment_status"] = "cancelled";
				$entry["payment_amount"] = 0;
				$entry["is_fulfilled"]   = 0;
				GFAPI::update_entry($entry);

				$note = sprintf(__('وضعیت پرداخت : کنسل شده <br> مبلغ پرداختی : %s <br> کد تراکنش : %s', "gravityformsdigidargah"), $total_money, $transaction_id);
			
			}
			
			else {
				$entry["payment_status"] = "failed";
				$entry["payment_amount"] = 0;
				$entry["is_fulfilled"]   = 0;
				GFAPI::update_entry($entry);

				$note = sprintf(__('وضعیت پرداخت : ناموفق <br> مبلغ پرداختی : %s <br> کد تراکنش : %s <br> خطا : %s', "gravityformsdigidargah"), $total_money, $transaction_id, $message);
			}

			$entry = GFAPI::get_entry($entry_id);
			
			RGFormsModel::add_note($entry["id"], $user_id, $user_name, $note);
			
			do_action('gform_post_payment_status', $config, $entry, strtolower($status), $transaction_id, '', $total, '', '');
			
			do_action('gform_post_payment_status_' . __CLASS__, $config, $form, $entry, strtolower($status), $transaction_id, '', $total, '', '');

			if (apply_filters('gf_digidargah_verify', apply_filters('gf_gateway_verify', ($payment_type != 'custom'), $form, $entry), $form, $entry)) {
				
				//
				$notifications = GFCommon::get_notifications_to_send('form_submission', $form, $entry);
				$_notifications = array();
				foreach((array) $notifications as $notification ) {
					$logic = rgar($notification, 'conditionalLogic');
					$rules = rgar($logic, 'rules');
					if (empty($rules)) continue;
					$fieldIds = wp_list_pluck($rules, 'fieldId');
					if (in_array('payment_status', $fieldIds)) $_notifications[] = $notification;
				}
				
				if (!empty($_notifications)){
					$_notifications = wp_list_pluck($_notifications, 'id');
					GFCommon::send_notifications($_notifications, $form, $entry);
				}
				
				//
				$confirmation = GFFormDisplay::handle_confirmation( $form, $entry );
				if (is_array($confirmation) && isset($confirmation["redirect"])) {
					header("Location:{$confirmation["redirect"]}");
					exit;
				}
				
				if (!empty($message))
					$confirmation .= '
					<script>
					var message_div = document.getElementById("gform_confirmation_message_' . $form['id'] . '");
					message_div.innerHTML += "<strong> متاسفانه پرداخت با خطا مواجه شد. پاسخ درگاه : ' . $message . ' </strong>";
					</script>';
					
				GFFormDisplay::$submission[$form['id']] = array(
					"is_confirmation" => true,
					"confirmation_message" => $confirmation,
					"form" => $form,
					"entry" => $entry,
					"lead" => $entry,
					"page_number" => 1
				);
			}
		}
	}
}

?>
