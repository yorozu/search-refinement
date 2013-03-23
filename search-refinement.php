<?php
/*
Plugin Name: Search Refinement
Version: 0.9.0
Plugin URI: 
Description: カスタムフィールドの値で投稿を絞り込むためのプラグイン
Author: Yoshitaka KATO
Author URI: http://www.djcom.jp/
*/

define('SEAREF_VERSION', '0.9.0');
define('SEAREF_DIR_URI', plugin_dir_url(__FILE__));
define('SEAREF_DIR_PATH', plugin_dir_path(__FILE__));
define('SEAREF_TEXTDOMAIN', 'search-refinement');

$inc_dirs[] = SEAREF_DIR_PATH . 'modules';
foreach ( $inc_dirs as $modules_dir ) {
	if ( is_dir($modules_dir) && $dh = opendir($modules_dir) ) {
		while ( ( $module = readdir( $dh ) ) !== false ) {
			if ( substr($module, -4) == '.php' && $module[0] != '_' ) {
				include_once $modules_dir . '/' . $module;
			}
		}
	}
}

class SearchRefinement extends Form_Tags {
	var $query_var; //絞り込み用
	var $separater; //URL内で使用するセパレータ
	var $action_url; //絞り込み検索Form用
	var $post__in;	//(array)記事IDでの絞込み用
	var $post__not_in;  //(array)記事一覧で除外する記事
	var $searef_request = array(); //絞込みリクエスト内容を格納
	var $posts_where; //フィルター後の posts_where
	var $posts_join;
	var $searef_result; //結果の格納用
	var $selected_category;
	var $args; //search_refinement()の引数格納用。デバッグ的
	var $sub_query_metavalue; //絞り込みに直接利用するwhere分格納用
	var $title_mapping = array(); //metakeyをlabelに置き換えるためのリスト
	var $inputhiddens = array();

	function __construct() {
		$this->init();		
		if ( !is_admin() ){
			add_action('init',			array(&$this, 'set_title_mapping'), 9);
			add_action('parse_request', array(&$this, 'add_query_var'), 9);
			add_action('parse_request', array(&$this, 'searef_parse_request'), 11);
			add_filter('posts_where',   array(&$this, 'sql_search_where'), 65535);
			add_filter('posts_where',   array(&$this, 'sql_exclude'),	 65536);
			add_filter('posts_join',	array(&$this, 'set_posts_join'), 65535);
			add_filter('single_cat_title',  array(&$this, 'add_searched_title'), 11);
			add_filter('single_tag_title',  array(&$this, 'add_searched_title'), 11);
			add_filter('single_term_title', array(&$this, 'add_searched_title'), 11);
		}
	}
	
	function init(){
		load_plugin_textdomain( SEAREF_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/lang/' );
		
		$this->query_var = 'searef';
		$this->args = $this->set_options();
		$this->set_separater();
	}
	function set_title_mapping($titles=''){
		if ( is_array($titles) && count($titles) >= 1 ){
			foreach ( $titles as $orig => $title ){
				$this->title_mapping[esc_attr($orig)] = esc_attr($title);
			}
		} else {
			$this->title_mapping = apply_filters('searef_title_mapping', $this->title_mapping);
		}
	}
	function set_separater(){
		$this->separater = urlencode("\t");
	}
	function get_separater(){
		return $this->separater;
	}
	function set_options($option_value=''){
		global $cat;
		
		$defaults = array(
			'title' => __('Search Refinement'), 
			'exclude_terms' => '',
			'catid' => $cat,
			'meta_key' => '',
			'order' => 'DESC',
			'orderby' => 'date',
			'order_in_cat' => '',
			'subcategories' => false,
			'display' => true,
			'sudden' => true,
		);
		return wp_parse_args( $option_value, $defaults );
	}
	
	function add_query_var($wp_query){ // POST/GETに利用できるパラメタ追加 //
		$wp_query->add_query_var($this->query_var);
	}
	
	// パラメタ分析 //
	function searef_parse_request($args){
		foreach ( $_REQUEST as $req_key => $req_value ){
			if ( $req_key == $this->query_var && !empty($req_value) ) {
				$r = wp_parse_args((array)$req_value);
				$separater = $this->get_separater();
				foreach ( $r as $value ){
					$s = preg_split('/('.$separater.'|'.urldecode($separater).')/', $value);
					if ( count($s) == 2 ){
						$metakey   = $s[0];
						$metasign  = 'eq';
						$metavalue = $s[1];
					} elseif ( count($s) >= 3 ){
						$metakey   = $s[0];
						$metasign  = $s[1];
						$metavalue = $s[2];
						if ( isset($s[3]) && !empty($s[3]) && !is_array($s[3]) ) {
							$this->set_title_mapping( array( $metavalue => $this->__($metavalue).$s[3] ) );
						}
					} else {
						continue;
					}
		
					switch ( $metasign ){
						case 'gt':
							$search_values[$metakey]['gt'] = $metavalue;
							$this->sub_query_metavalue[$metakey]['gt'] = sprintf("`meta_value` >= %s", (int)$metavalue);
							break;
						case 'lt':
							$search_values[$metakey]['lt'] = $metavalue;
							$this->sub_query_metavalue[$metakey]['lt'] = sprintf("`meta_value` <= %s", (int)$metavalue);
							break;
						default:
							$search_values[$metakey]['eq'] = $metavalue;
							$this->sub_query_metavalue[$metakey][] = sprintf("`meta_value` = '%s'", $metavalue);
					}
				}
			} else {
				$this->set_inputhidden( array($req_key => $req_value) );
			}
		}

		$this->set_action_url();
		if ( isset($search_values) ){
			$this->searef_request = $search_values;
		} else {
			$this->searef_request = array();
		}
	}//endFunction
	
	function set_action_url($req=''){
		$uri = preg_split('/\?/', $_SERVER['REQUEST_URI'], 2);
		if ( isset($uri[1]) ){
			$args = wp_parse_args($uri[1]);
			if ( isset($args['cat']) && $args['cat'] > 0 ){
				$this->action_url = get_category_link($args['cat']);
			} else{
				$this->action_url = get_bloginfo('url') . $uri[0];
			}
		} else {
			$this->action_url = get_bloginfo('url') . $_SERVER['REQUEST_URI'];
		}
	}
	function get_action_url($args=''){
		$r = wp_parse_args($args);
		if ( isset($r['cat']) ){
			return get_category_link((int)$r['cat']);
		} else if (  isset($r['cat']) ){
			return get_category_link((int)$r['default_cat']);
		}
		return $this->action_url;
	}
	
	// リストアップする記事の絞り込み
	function sql_search_where( $where ){
		global $wpdb, $wp_the_query, $wp_query;
		if ( is_singular() ) { return $where; }
		if ( !is_admin() && $wp_the_query !== $wp_query ) { return $where; }

		$sub_where = array();
		$post_in = '';
		$search_values = $this->searef_request;
		
		foreach ( (array)$wp_the_query->get('post_type') as $value ) {
			if ( $value ){
				$post_type[] = "`post_type` = '{$value}'";
			}
		}
		if ( isset($post_type) ){
			$post_type = '(' . join(' OR ', $post_type) . ')';
		} else {
			$post_type = "`post_type` = 'post'";
		}

		if ( is_array($search_values) ) {
			$sub_where = array($where);
			foreach ( $search_values as $meta_key => $meta_value ) {
				$key = urldecode($meta_key);				
				
				if ( isset($this->sub_query_metavalue[$key]) && count($this->sub_query_metavalue[$key]) ){
					foreach ( (array)$this->sub_query_metavalue[$key] as $sign => $sub_query_metavalue ){
						$post_ids = array();
						$sub_query = (array)"SELECT DISTINCT post_id FROM {$wpdb->postmeta}";
						$sub_query[] = "INNER JOIN {$wpdb->posts} ON ({$wpdb->postmeta}.post_id = {$wpdb->posts}.ID)";
						$sub_query[] = "WHERE {$post_type}";
						$sub_query[] = "AND `meta_key` = '{$key}' AND " . $sub_query_metavalue;
						$sub_query[] = ( is_user_logged_in() ) ? "AND (`post_status` = 'publish' OR `post_status` = 'private')" : "AND `post_status` = 'publish'";
						//サブクエリー実行と値格納//
						$sub_query_result = $wpdb->get_results( join(' ',$sub_query), ARRAY_A );
						
						if ($sub_query_result) {
							foreach ( $sub_query_result as $tmpvalue ) {
								$post_ids[] = $tmpvalue['post_id'];
							}
							if ( !is_numeric($sign) ){
								$sub_where_key = $meta_key . "%09" . $sign;
							} else {
								$sub_where_key = $meta_key . "%09eq";
							}
							$sub_where[$sub_where_key] = "{$wpdb->posts}.ID IN (" . join(',', $post_ids) . ")";
							if ( empty($post_in) ){
								$post_in = $post_ids;
							} else {
								$post_in = array_intersect($post_in, $post_ids);
							}
						} else {
							$sub_where[$meta_key] = '1=0';
						}
					}
				}
			}//endForeach
			
			$this->post__in = $post_in;
			$where = join(' AND ', $sub_where);
			
		} else {
			$sub_where[0] = " AND wp_posts.post_type = 'post' ";
			$sub_where[0] .= ( is_user_logged_in() ) ? 
				"AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'private')" :
				"AND (wp_posts.post_status = 'publish' )";
			
		}//endIf isset $search_values
		$this->posts_where = $sub_where;
		return $where;
	}//endFunction
	
	function sql_exclude( $where ){
		global $wpdb, $wp_the_query, $wp_query;
		if ( !is_admin() && $wp_the_query !== $wp_query ) { return $where; }
		
		$exclude_ids = array();
		$exclude_terms = ( isset($this->args['exclude_terms']) ) ? $this->args['exclude_terms'] : '';

		if ( $exclude_terms && (is_archive() || is_home() || is_front_page()) ){
			foreach ( $exclude_terms as $term ){
				$query   = array();
				$query[] = "SELECT DISTINCT post_id,meta_value";
				$query[] = "FROM {$wpdb->postmeta}";
				$query[] = 'WHERE meta_key = "' . $term . '"';
				$query   = join("\n", $query);
				$result = $wpdb->get_results($query, ARRAY_N);
				foreach ( (array)$result as $key => $value ) {
					if ( ! empty($value[1]) ){
						$this->posts_where[$term] = array_diff($this->posts_where[$term], $value[0]);
						$exclude_ids[$term] = $value[0];
					}
				}
			}

			if ( ! empty($exclude_ids) ){
				$where = $where . ' AND (' . "{$wpdb->posts}.ID" . ' NOT IN (' . join(',', (array)$exclude_ids) . '))';
			}
		}
		//$this->posts_where = $where;
		$this->post__not_in = $exclude_ids;
		return $where;
	}
	
	function set_posts_join( $join ){
		$this->posts_join = $join;
		return $join;
	}

	function get_stats($key=''){
		global $wp_query, $cat;
		$output['cat'] = $cat;
		$output['found_posts'] = $wp_query->found_posts;
		
		extract($this->args, EXTR_SKIP);
		
		$output['title'] = (isset($title)) ? $title : __('Search');
		$output['terms'] = (isset($terms)) ? $terms : '';
		$output['orderby'] = (isset($orderby)) ? $orderby : '';
		$output['order'] = (isset($order)) ? $order : '';
		
		//
		$output['count'] = ( empty($this->searef_request) ) ? 0 : count($this->searef_request);
		$output['post_in']    = join( ',', array_diff((array)$this->post__in, (array)$this->post__not_in) );
		
		if ( empty($key) ){
			return $output;
		} else {
			return $output[$key];
		}
	}
	
	function the_message($args=''){
		global $wp_query;
		$defaults = array(
			'echo' => 1,
			'found_posts' => $wp_query->found_posts,
			'msg_wrap_before' => '<div class="message %s">',
			'msg_wrap_after' => '</div>',
		);
		$r = wp_parse_args( $args, $defaults );
		extract($r, EXTR_SKIP);
		
		if ( count( $this->searef_request ) || is_archive() ){
			if (is_numeric($found_posts) && $found_posts > 0) {
				$msg[] = sprintf($msg_wrap_before, ' ');
				$msg[] = sprintf( __('%s items found', SEAREF_TEXTDOMAIN), '<span class="count">' . (int)$found_posts . '</span>' );
				$msg[] = $msg_wrap_after;
			} else {
				$msg[] = sprintf($msg_wrap_before, 'notfound');
				$msg[] = __('No items found.');
				$msg[] = $msg_wrap_after;
			}
		}
		if ( $echo && isset($msg) ){
			echo join('',(array)$msg);
		}
		return $found_posts;
	}

	function get_searched_title($title){
		$searef_request = $this->searef_request;
		if ( !empty($searef_request) ){
			foreach ( $searef_request as $meta_key => $meta_values ){
				foreach ( $meta_values as $sign	=> $meta_value ) {
					if ( !empty($meta_value) ){
						$v = $this->__( $this->unserialize($meta_value) );
						// suffixが追加されるようにする予定
						switch ( $sign ){
							case 'gt':
								$output[] = sprintf(__('%s~',SEAREF_TEXTDOMAIN), $v);
								break;
							case 'lt':
								$output[] = sprintf(__('~%s',SEAREF_TEXTDOMAIN), $v);
								break;
							default:
								$output[] = $v;
						}
					}
				}
			}
		}
		return ( isset($output) ) ? $output : '';
	}
	
	//function add_searched_title($title){
	function add_searched_title($title){
		$output = $this->get_searched_title($title);
		if ( empty($output) ){
			return $title;
		} else {
			$keyword = '"' . join('" "', $output) . '"';
			$message = sprintf(__('Search for %s, %s items found',SEAREF_TEXTDOMAIN), $keyword, $this->get_stats('found_posts'));
			return $message . ' | ' . $title;
		}
	}
	
	function __($value){
		if ( isset($this->title_mapping[$value]) ){
			$output = $this->title_mapping[$value];
		} else {
			$output = trim( strip_tags(urldecode($value)) );
		}
		return $output;
	}
	
	function get_selection($args=''){
		global $wpdb, $wp_query;
		$defaults = array(
			'meta_key' => '',
			'meta_sign' => '',
			'target_value' => '',
			'default_cat' => '',
		);
		$r  = wp_parse_args( $args, $defaults );	
		extract($r, EXTR_SKIP);

		$posts_where = (array)$this->posts_where;
		$where = array(" WHERE meta_key='" . $r['meta_key'] . "'"); 
		$where[] = ( is_user_logged_in() ) ? "AND (`post_status` = 'publish' OR `post_status` = 'private')" : "AND `post_status` = 'publish'";
		$where[] = array_shift($posts_where);
		foreach  ( $posts_where as $w_key => $w ){
			if ( $w_key != $r['meta_key'] . "%09" . $r['meta_sign'] ){
				$where[] = ' AND ' . $w;
			}
		}
		
		if ( strpos($this->posts_join, $wpdb->postmeta) === false ){
			$inner = array(" INNER JOIN {$wpdb->postmeta} ON ({$wpdb->postmeta}.post_id = {$wpdb->posts}.ID)");
		} else {
			$inner = array();
		}
		if ( ( (is_home() && $is_home) || !is_archive() ) && $default_cat ){ 
			$inner[] = "INNER JOIN {$wpdb->term_relationships} AS tr ON (tr.object_id = {$wpdb->posts}.ID)";
			$inner[] = "INNER JOIN {$wpdb->term_taxonomy} AS tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id)";
			$inner[] = "INNER JOIN {$wpdb->terms} AS t ON (t.term_id = tt.term_id)";
			if ( !is_array($default_cat) ){
				$default_cat = array('cat' => $default_cat);
			}
			foreach ( (array)$default_cat as $key => $value ){
				switch ($key){
					case 'cat':
						$value = (int)$value;
						$where[] = "AND tt.taxonomy = 'category' AND (tt.term_id = '{$value}' OR tt.parent = '{$value}') ";
						break;
					case 'taxonomy':
						$where[] = "AND tt.taxonomy = '{$value}'";
						break;
					case 'term':
						//$where[] = "AND (t.slug = '{$value}' OR tt.parent = t.term_id)"; // DEBUG:再帰的にサブカテゴリを読みたい //
						break;
				}
			}
		}
		
		$q_select = "SELECT DISTINCT meta_key,meta_value";
		$q_from   = " FROM {$wpdb->posts} " . $this->posts_join;
		$q_inner  = ' ' . join(' ', $inner);
		$q_where  = ' ' . join(' ', $where);
		$q_order  = " ORDER BY meta_value ";
		$result = $wpdb->get_results( $q_select.$q_from.$q_inner.$q_where.$q_order, ARRAY_N );
		foreach ( $result as $value ){
			$key = trim(strip_tags($value[0]));
			$value = strip_tags($value[1]);
			if ( $r['meta_key'] == $key ){
				$values[] = $value;
			}
		}
		if ( isset($values) ){
			if ( $r['target_value'] ){
				$values = array_unique( array_merge($values, (array)$r['target_value']) );
			}
			natcasesort($values);
			return $values;
		} else {
			return '';
		}
	}
	
	function html_resetbutton($args=''){
		global $cat;
		$defaults = array(
			'action' => get_category_link($cat),
			'title' => __('Reset your select'),
		);
		$r  = wp_parse_args( $args, $defaults );	
		extract($r, EXTR_SKIP);
		
		$html = '<div class="reset-button"><form method="get" name="reset" action="' . $action . '">';
		$html .= $this->html_inputhiddens();
		$html .= '<input type="submit" value="' . __($title) . '" title="' . __($title) . '" onclick="on_submit_search(this.form);return false;" />';
		$html .= '</form></div>';
		
		return $html;
	}
	function set_inputhidden($data){
		if ( is_array($data) ){
			foreach ( $data as $key => $value ){
				$this->inputhiddens[$key] = $value;
			}
		}
	}
	function get_inputhidden(){
		return $this->inputhiddens;
	}
	function html_inputhiddens($args=''){
		$html = array();
		$sets = $this->get_inputhidden();
		$r  = wp_parse_args( $sets, $args );
		foreach ( $r as $name => $value ){
			$html[] = sprintf('<input type="hidden" name="%s" value="%s" />', $name, $value);
		}
		return join('',$html);
	}
	
	function unserialize($value=''){
		if ( empty($value) ) { return; }
		
		if ( is_serialized($value) ) {
			$_t = unserialize(stripslashes_deep($value));
			if ( is_array($_t) && count($_t) == 1 ){
				return array_shift($_t);
			} else {
				return $_t;
			}
		} else {
			return $value;
		}
		
	}
	
} //endclass SearchRefinement

function searef_searchbox($args=''){
	global $searchrefinement;
	global $wpdb;
	$defaults = array(
		'title' => '',
		
		'default_cat' => array(),
		'echo_message' => true,
		'wrap_tag_before' => '<ul>',
		'wrap_tag_after' => '</ul>',
		'section_wrap_before' => '<li class="%s">',
		'section_wrap_after' => '</li>',
		'label_before' => '<label for="%s">',
		'label_after' => ':</label>',
		'select_tag_before' => '<span class="selection">',
		'select_tag_after' => '</span>',
		'is_home' => false,
		'display_reset_button' => true,
	);
	// and, Depend $this->args
	$defaults = wp_parse_args( $searchrefinement->get_stats(), $defaults );
	$r = wp_parse_args( $args, $defaults );	
	extract($r, EXTR_SKIP);

	if ( !is_array($default_cat) ){
		if ( is_numeric($default_cat) ){
			$default_cat = array('cat' => (int)$default_cat);
		} else {
			$taxonomies = wp_parse_args($default_cat);
			foreach ( $taxonomies as $k => $v ){
				if ( $v ){
					$_dc[esc_attr($k)] = esc_attr($v);
				}
			}
			$default_cat = (isset($_dc)) ? $_dc : '';
		}
	}
	if ( (is_singular() || (is_home() && $is_home)) ) {
		$html_inputhiddens = $searchrefinement->html_inputhiddens($default_cat);
	} else {
		$html_inputhiddens = $searchrefinement->html_inputhiddens();
	}
	$onchange = (isset($onchange) && ($onchange == 'false' || $onchange == false) ) ? false : true;
	?>

    <div class="search-refinement">
        <?php if ( ! empty( $title ) ) : ?><h3><?php echo $title; ?></h3><?php endif; //$title ?>
		<?php //Reset Button
			if ( $display_reset_button ) { echo $searchrefinement->html_resetbutton('action='.$searchrefinement->get_action_url()); }
		?>

        <div class="search-refinement-selecter">
        	<img src="/wp-admin/images/loading.gif" title="now Searching..." alt="now Searching..." class="nowsearching" style="display:none; float:left;" />
			<?php if ( $echo_message ) { $searchrefinement->the_message(); } ?>
            <form name="choice" action="<?php echo (is_home() && !$is_home) ? get_bloginfo('url') : $searchrefinement->get_action_url($default_cat); ?>" method="GET">
				<?php echo $html_inputhiddens; ?>
				<?php echo $wrap_tag_before; ?>
					<?php //検索用フォームを出力
					foreach ( $terms as $term ) :
						$meta_id   = $term['key'];
						$meta_name = ( empty($term['label']) ) ? $term['key'] : $term['label'];
						$sep = $searchrefinement->get_separater();
						$suffix = (isset($term['suffix'])) ? $term['suffix'] : '';
						
						if ( isset($searchrefinement->searef_request[$meta_id]) ){
							$meta_values = $searchrefinement->searef_request[$meta_id];
							if ( $term['range'] ){
								if ( !isset($meta_values['lt']) ){
									$meta_values['lt'] = '';
								}
								if ( !isset($meta_values['gt']) ){
									$meta_values['gt'] = '';
								}
							}
						} else {
							if ( $term['range'] ){
								$meta_values = array('gt'=>'', 'lt'=>'');
							} else {
								$meta_values = array('eq'=>'');
							}
						}
						ksort($meta_values); //gt~lt (temporary...)
						?>
						
						<?php 
						$attr_id = esc_attr(preg_replace('/ /','_',$meta_id));
						$select_id = 'selecton-' . $attr_id;
						printf($section_wrap_before, $attr_id);
							printf($label_before, $select_id); 
							echo $meta_name . $label_after;
							?>
							<?php printf($select_tag_before); ?>
							<?php foreach ( $meta_values as $meta_sign => $meta_value ) : ?>
								
								<select id="<?php echo $select_id; ?>" name="searef[]" <?php if ( $onchange ) : ?>onChange="on_submit_search(this.form);"<?php endif; ?> class="<?php echo esc_attr($meta_sign); ?>" >
									<option value=""><?php _e('&mdash; Select &mdash;'); ?></option>
									<?php
									$sel_args = array(
										'meta_key' => $meta_id,
										'meta_sign' => $meta_sign,
										'target_value' => $meta_value,
										'default_cat' => $default_cat,
										'is_home' => $is_home,
									);
									foreach ( (array)$searchrefinement->get_selection($sel_args) as $value ) :
										if ( empty($value) ) { continue; }
										if ( isset($meta_sign) ){
											$option_value = $meta_id . $sep . $meta_sign . $sep . $value;
										} else {
											$option_value = $meta_id . $sep . $value;
										}
										$option_name = $searchrefinement->__( $searchrefinement->unserialize($value) );
										if ( $suffix ){
											$option_value = $option_value . $sep . $suffix;
											if ( !preg_match('/'.$suffix.'$/', $option_name) ) { //temporary...
												$option_name = $option_name . $suffix;
											}
										}
										if ( $value == $meta_value ){
											$selected = true;
											$link_param[] = 'searef[]=' . $option_value;
										} else {
											$selected = false;
										}
										echo Form_tags::selectoption(
												$option_value,
												$option_name,
												$selected
										);
									endforeach; //$selection[$meta_id] ?>
								</select>
								
								<?php if ( $meta_sign == 'gt' ) { _e('~'); } ?>
							<?php endforeach; ?>
							<?php echo $select_tag_after; ?>
						<?php echo $section_wrap_after; ?>
						
					<?php endforeach; // $terms as $meta_id => $meta_name ?>
					
				<?php echo $wrap_tag_after; ?>

				<?php if ( $onchange ) : ?><noscript><?php endif; ?>
				<div id="submit"><input type="submit" value=" <?php _e('Search'); ?> " onclick="on_submit_search(this.form);return false;" /></div>
				<?php if ( $onchange ) : ?></noscript><?php endif; ?>
            </form>
			
            <?php if ( 0 && current_user_can('edit_themes') && isset($link_param) ) :
				$permalink = '<input type="text" value="' . get_bloginfo('url') . '?' . join('&',$link_param)  . '" />';
				?><div class="permalink"><?php printf(__('Permalink: %s'),$permalink); ?></div><?php
			endif; ?>
        </div>
		
    </div><!-- .search-refinement -->

<script type="text/javascript">
function on_submit_search(form){
	pos = '#search-refinement';
	jQuery('.nowsearching').fadeIn(1);
	form.submit();
	return false;
}
</script>

	<?php
	//結果を返して終了
	return $searchrefinement->searef_result;
}
global $searchrefinement;
$searchrefinement = new SearchRefinement();
?>