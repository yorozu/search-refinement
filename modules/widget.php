<?php
/**
 * search-refinement/search-refinement-widget.php
 */

add_action('widgets_init', create_function('', 'return register_widget("SEAREF_Widget");'));

class SEAREF_Widget extends WP_Widget {
	public $name;
	
	function __construct() {
		add_action('admin_enqueue_scripts', array(&$this, 'set_jquery_sortable') );
		parent::WP_Widget(false, $this->name = __('Search Refinement', SEAREF_TEXTDOMAIN) );
	}
	
	function set_jquery_sortable(){
		wp_enqueue_script('jquery-ui-sortable', '', array('jquery'), '', true);
		wp_enqueue_script('searef_widget', SEAREF_DIR_URI.basename(dirname(__FILE__)).'/widget.js', array('jquery-ui-sortable'), SEAREF_VERSION, true);
	}
	
	function widget($args, $instance) {
		global $searef_admin;
		$defaults = array(
			'default_cat' => '',
			'is_home' => '',
		);
		$r = wp_parse_args( $instance, $defaults );
		$widget_class = esc_attr($r['widget_class']);
		
		if ( isset($r['term_key']) && is_array($r['term_key']) ){
			foreach ( $r['term_key'] as $k => $v ){
				if ( isset($v['key']) && $v['key'] ){
					$terms[$k]['key'] = $v['key'];
					$terms[$k]['label']  = ( isset($v['label'])  && $v['label']       ) ? $v['label'] : $v['key'];
					$terms[$k]['range']  = ( isset($v['range'])  && $v['range']=='on' ) ? true : false;
					$terms[$k]['suffix'] = ( isset($v['suffix']) && $v['suffix']      ) ? $v['suffix'] : '';
				}
			}
			
			if ( is_numeric($r['default_cat']) && $r['default_cat'] > 0 ){
				$default_cat = array('cat' => (int)$r['default_cat']);
			} else {
				$taxonomies = wp_parse_args($r['default_cat']);
				foreach ( $taxonomies as $k => $v ){
					if ( $v ){
						$default_cat[esc_attr($k)] = esc_attr($v);
					}
				}
			}
			$search_args = array(
				'default_cat' => (isset($default_cat)) ? $default_cat : '',
				'is_home' => ($r['is_home']=='on') ? true : false,
				'title' => apply_filters( 'widget_title', ($r['title']) ? $r['title'] : '' ),
				'terms' => $terms,
			);
			?>
			<li class="searef_widget <?php echo $widget_class; ?>">
				<?php searef_searchbox( $search_args ); ?>
			</li>
			<?php
		}
	}

	function form($instance) {
		global $wpdb, $searef_admin;
		$defaults = array(
			'title' => '',
			'widget_class' => '',
			'default_cat' => 0,
			'onchange' => '',
			'field_name' => 'term_key',
		);
		$r = wp_parse_args( $instance, $defaults );
		extract($r, EXTR_SKIP);
		$meta_keys = $this->get_meta_keys();
		$empty = 0;
		$class_sortable = 'sortable-terms';

		if ( !isset(${$field_name}) ){
			${$field_name} = array();
		} else {
			foreach ( ${$field_name} as $key => $value ){
				if ( isset($value['key']) && $value['key'] ){
					$fields[] = $value;
				}
			}
		}
		$fields[] = array();
        ?>
        <div><?php echo $this->_html_inputtext('title', $title, __('Title').':'); ?></div>
        <div class="<?php echo $class_sortable; ?>">
			<?php foreach ( $fields as $field_key => $field_value ) :
				$_f = sprintf("%s[%s]", $this->get_field_name($field_name), $field_key);
				?>
            
                <fieldset id="<?php echo $this->get_field_id($field_name); echo $field_key;?>" style="background-color:#eee; border-top:1px thin #999; margin-top:8px; padding:6px;" class="term-set">
					
                    <div class="term-title"><?php printf("%s%d:", __('Meta'), $field_key+1); ?>&nbsp;
						<?php if ( isset($field_value['key']) ) : ?>
						<strong><?php echo ($field_value['label']) ? $field_value['label'] : $field_value['key']; ?></strong>
						<?php endif; ?>
					</div>
                    <?php $_opt = 'key'; ?>
                    <select name="<?php echo $_f; ?>[<?php echo $_opt; ?>]" class="metakeyselect widefat">
                        <option value=""><?php _e('&mdash; Select &mdash;'); ?></option><?php
                        foreach ( $meta_keys as $opt_value) {
                            $selected = ( !empty($field_value[$_opt]) && $field_value[$_opt] == $opt_value ) ? 1 : 0;
                            echo Form_Tags::selectoption($opt_value, $opt_value, $selected);
                        }
                    ?></select>
					
					<div class="alignright">
						<a href="javascript:void(0);" onclick="jQuery(this).parent().parent().children('.advance').slideToggle(100); return false;"><?php _e('Advance'); ?></a>
					</div>
					<div class="advance" style="display:none;">
						<div class="term-label" style="padding:4px;">
							<?php _e('Label'); ?>
							<?php echo Form_tags::textinputbox('label', $field_value, $_f); ?>
						</div>
						<?php $_opt = 'range';
						if ( isset($field_value[$_opt]) && $field_value[$_opt] == 'on' ){
							$checked = 'checked="checked"';
						} else {
							$checked = '';
						}						
						?><div class="search-<?php echo $_opt; ?>" style="padding:4px;">
							<?php _e('Search range'); ?>
							<input id="<?php echo $this->get_field_id($_opt); ?>-<?php echo $field_key; ?>" name="<?php printf('%s[%s]',$_f,$_opt); ?>" type="checkbox" <?php echo $checked; ?> />
							<a title="<?php _e('Use function searching integers which range of lower to higher.',SEAREF_TEXTDOMAIN); ?>" class="help" style="color:#333; border-bottom:#333 dotted 1px;">(?)</a>
						</div>
						
						<?php $_opt = 'suffix'; 
						?><div class="term-suffix" style="padding:4px;">
							<?php _e('Suffix'); ?>
							<?php echo Form_tags::textinputbox($_opt, $field_value, $_f); ?>
						</div>
					</div>
                </fieldset>
            <?php endforeach; ?>
            <div>
				<img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" class="ajax-feedback" title="" alt="" />
				<?php submit_button( __('Save'), 'widget-control-save button', 'addrow', false ); ?>
			</div>
        </div>
		
		<h5><?php _e('Options'); ?></h5>
        <div class="default_term">
			<h6><?php _e('デフォルトカテゴリー'); ?></h6>
			<div>
				<label for="<?php echo $this->get_field_id('default_cat'); ?>"><?php _e('Default term ID:', SEAREF_TEXTDOMAIN); ?></label>
				<input class="" id="<?php echo $this->get_field_id('default_cat'); ?>" name="<?php echo $this->get_field_name('default_cat'); ?>" type="text" size="10" value="<?php echo $default_cat; ?>" />
			</div>
			<div>
				<?php $_opt = 'is_home';
				if ( isset(${$_opt}) && ${$_opt} == 'on' ){
					$checked = 'checked="checked"';
				} else {
					$checked = '';
				}						
				_e('homeでもデフォルト分類に移動する');
				?><input id="<?php echo $this->get_field_id($_opt).'-'.$field_key; ?>" name="<?php echo $this->get_field_name($_opt); ?>" type="checkbox" <?php echo $checked; ?> />
			</div>
        </div>
		<div><?php echo $this->_html_inputtext('widget_class', $widget_class, 'Widget class:'); ?></div>
		<label for="<?php echo $this->get_field_id('onchange'); ?>"><?php _e('Search suddenly:'); ?></label><input class="" id="<?php echo $this->get_field_id('onchange'); ?>" name="<?php echo $this->get_field_name('onchange'); ?>" type="checkbox" <?php if ( $onchange !== false ): ?>checked="checked"<?php endif; ?> />
		<script type="text/javascript">jQuery(document).ready(function(){Searef.sortable('.<?php echo $class_sortable; ?>');})</script>
        <?php 
	}

	function update($new_instance, $old_instance) {
		foreach ( $new_instance as $key => $value ){
			if ( !is_array($value) ){
				$sanitized[$key] = strip_tags( trim($value) );
			} else {
				$sanitized[$key] = $value;
			}
		}
        return $sanitized;
	}
	
	function post_is_descendant_category($cat){
		$term = (int)$cat;
		$taxonomyName = 'category';
		
		if ( $term > 0 ) {
			if ( is_category($term) || is_category(get_term_children($term, $taxonomyName)) ){
				return true;
			}
		}
		return false;
	}
	
	function get_meta_keys(){
		global $wpdb;		 
		if (false == ( $keys = get_transient('get_meta_keys') ) ) {
			$keys = get_meta_keys();
			set_transient('get_meta_keys', $keys, 60*3);
		}
		return $keys;
	}
	
	function _html_inputtext($name='', $value='', $label='', $class=''){
		if ( empty($name) ) { return; }
		if ( empty($label) ) { $label = $name; }
		$html = '<label for="' . $this->get_field_id($name) . '">' . $label . '</label>';
		$html .= '<input class="' . esc_attr($class) . '" id="' . $this->get_field_id($name) . '" name="' . $this->get_field_name($name) . '" type="text" value="' . $value . '" />';
		return $html;
	}
}

?>