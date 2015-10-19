<?php
/*
Plugin Name: Display Widgets
Plugin URI: http://strategy11.com/display-widgets/
Description: Adds checkboxes to each widget to show or hide on site pages.
Author: Strategy11
Author URI: http://strategy11.com
Version: 2.05
Text Domain: display-widgets
*/

/*
// Change the hook this is triggered on with a bit of custom code. Copy and paste into your theme functions.php or a new plugin.
add_filter('dw_callback_trigger', 'dw_callback_trigger');
function dw_callback_trigger(){
    return 'wp_head'; //plugins_loaded, after_setup_theme, wp_loaded, wp_head
}
*/


class DWPlugin{
	var $transient_name = 'dw_details';
	var $checked = array();
	var $id_base = '';
	var $number = '';
    
	// pages on site
	var $pages = array();
    
	// custom post types
	var $cposts = array();
    
	// taxonomies
	var $taxes = array();
    
	// categories
	var $cats = array();
    
	// WPML languages
	var $langs = array();
    
	function __construct(){
		add_filter( 'widget_display_callback', array( &$this, 'show_widget' ) );
        
		// change the hook that triggers widget check
		$hook = apply_filters( 'dw_callback_trigger', 'wp_loaded' );
        
		add_action( $hook, array( &$this, 'trigger_widget_checks' ) );
		add_action( 'in_widget_form', array( &$this, 'hidden_widget_options'), 10, 3 );
		add_filter( 'widget_update_callback', array( &$this, 'update_widget_options' ), 10, 3 );
		add_action( 'wp_ajax_dw_show_widget', array( &$this, 'show_widget_options' ) );
		add_action( 'admin_footer', array( &$this, 'load_js' ) );
        
		// when a page is saved
		add_action( 'save_post_page', array( &$this, 'delete_transient' ) );
        
		// when a new category/taxonomy is created
		add_action( 'created_term', array( &$this, 'delete_transient' ) );
        
		// when a custom post type is added
		add_action( 'update_option_rewrite_rules', array( &$this, 'delete_transient' ) );
        
		// reset transient after activating the plugin
		register_activation_hook( dirname(__FILE__) . '/display-widgets.php', array( &$this, 'delete_transient' ) );
        
		add_action( 'plugins_loaded', array( &$this, 'load_lang' ) );

		// get custom Page Walker
		$this->page_list = new DW_Walker_Page_List();
	}
    
	function trigger_widget_checks() {
		add_filter( 'sidebars_widgets', array( &$this, 'sidebars_widgets' ) );
	}

	function get( &$var, $dflt=false ) { //NOTE: Works because $var passed by reference
		return isset( $var ) ? $var : $dflt;
	}

	function show_widget( $instance ) {
		$instance['dw_logged'] = self::show_logged( $instance );
        
		// check logged in first
		if ( in_array( $instance['dw_logged'], array( 'in', 'out' ) ) ) {
			$user_ID = is_user_logged_in();
			if ( ( 'out' == $instance['dw_logged'] && $user_ID ) || ( 'in' == $instance['dw_logged'] && ! $user_ID ) ) {
				return false;
			}
		}
       
		$post_id = get_queried_object_id();
		$post_id = self::get_lang_id( $post_id, 'page' );

		$checked = false;
		do {
			if ( is_home() ) {
				$checked = self::get( $instance['page-home'] );
				if ( $checked ) break;
			}
			if ( is_front_page() ) {
				$checked = self::get( $instance['page-front'] );
				if ( $checked ) break;
			}
			if ( is_category() ) {
				$checked = self::get( $instance['cat-all'] );
				if ( $checked ) break;

				$checked = self::get( $instance['cat-' . get_query_var('cat') ] );
				if ( $checked ) break;
			}
			if ( is_tax() ) {
				$term = get_queried_object();
				$checked = self::get( $instance[ 'tax-' . $term->taxonomy ] );
				unset( $term ); //unset because its an object
				if ( $checked ) break; //break after the unset
			}
			if ( is_post_type_archive() ) {
				$type = get_post_type();
				$checked = self::get( $instance[ 'type-' . $type . '-archive' ] );
				if ( $checked ) break;
			}
			if ( is_archive() ) {
				$checked = self::get( $instance['page-archive'] );
				if ( $checked ) break;
			}
			if ( is_single() ) {
				$type = get_post_type();
				if ( $type != 'page' && $type != 'post' ) {
					$checked = self::get( $instance[ 'type-' . $type ] );
				}
				if ( $checked ) break;

				$checked = self::get( $instance['page-single'] );
				if ( $checked ) break;

				$cats = get_the_category();
				foreach ( $cats as $cat ) {
					$c_id = self::get_lang_id( $cat->cat_ID, 'category' );
					$checked = self::get( $instance[ 'cat-' . $c_id ] );
					unset( $c_id, $cat );
					if ( $checked ) break; //exit the inner loop after the unset
				}
				if ( $checked ) break;

			}
			if ( is_404() ) {
				$checked = self::get( $instance['page-404'] );
				if ( $checked ) break;
			}
			if ( is_search() ) {
				$checked = self::get( $instance['page-search'] );
				if ( $checked ) break;
			}
			if ( $post_id && empty(get_query_var('cat'))) { //NOTE: post_id is the category_id on category pages, lets not filter that
				$checked = self::get( $instance[ 'page-' . $post_id ] );
				if ( $checked ) break;

				if ( isset( $instance['other_ids'] ) && ! empty( $instance['other_ids'] ) ) {
					$other_ids = explode( ',', $instance['other_ids'] );
					foreach ( $other_ids as $other_id ) {
						if ( $post_id == (int) $other_id ) {
							$checked = true;
							break; //exit the inner loop
						}
					}
					if ( $checked ) break;
				}
			}

	
			//TODO: is there a reason this has to be AFTER apply_filters() ?
        	        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			// check for WPML widgets
				$checked = self::get( $instance[ 'lang-' . ICL_LANGUAGE_CODE ] );
			}

			break; //break no matter what

		} while (true); //simplier way to exit out of that control block once we've found a match

		$checked = apply_filters( 'dw_instance_visibility', $checked, $instance ); //custom hook for themes/plugins to modify behavior

		//this is the 'hide on checked pages' vs 'show on checked pages' dropdown
		//defaults to hide
		$instance['dw_include'] = isset( $instance['dw_include'] ) ? $instance['dw_include'] : 0;
        
		if (
			( 1 == $instance['dw_include'] && false == $checked ) || 
			( 0 == $instance['dw_include'] && true  == $checked ) 
		   ) {
			return false;
		} 
		else if ( defined('ICL_LANGUAGE_CODE') && $instance['dw_include'] && $checked && ! isset( $instance[ 'lang-' . ICL_LANGUAGE_CODE ] ) ) {
			//if the widget has to be visible here, but the current language has not been checked, return false
			return false;
		}
        
		return $instance;
	}

	function sidebars_widgets( $sidebars ) {
		if ( is_admin() ) {
			return $sidebars;
		}

		global $wp_registered_widgets;

		foreach ( $sidebars as $s => $sidebar ) {
			if ( $s == 'wp_inactive_widgets' || strpos( $s, 'orphaned_widgets' ) === 0 || empty( $sidebar ) ) {
				continue;
			}

			foreach ( $sidebar as $w => $widget ) {
				// $widget is the id of the widget
				if ( ! isset( $wp_registered_widgets[ $widget ] ) ) {
					continue;
				}

				if ( isset( $this->checked[ $widget ] ) ) {
					$show = $this->checked[ $widget ];
				} else {
					$opts = $wp_registered_widgets[ $widget ];
					$id_base = is_array( $opts['callback'] ) ? $opts['callback'][0]->id_base : $opts['callback'];

					if ( ! $id_base ) {
						continue;
					}

					$instance = get_option( 'widget_' . $id_base );

					if ( ! $instance || ! is_array( $instance ) ) {
						continue;
					}

					if ( isset( $instance['_multiwidget'] ) && $instance['_multiwidget'] ) {
						$number = $opts['params'][0]['number'];
						if ( ! isset( $instance[ $number ] ) ) {
							continue;
						}

						$instance = $instance[ $number ];
						unset( $number );
					}

					unset( $opts );

					$show = self::show_widget( $instance );

					$this->checked[ $widget ] = $show ? true : false;
				}

				if ( ! $show ) {
					unset( $sidebars[ $s ][ $w ] );
				}

				unset( $widget );
			}
			unset( $sidebar );
		}

		return $sidebars;
	}
    
	function hidden_widget_options( $widget, $return, $instance ) {
		if ( $_POST && isset( $_POST['id_base'] ) && $_POST['id_base'] == $widget->id_base ) {
			// widget was just saved so it's open
			self::show_hide_widget_options( $widget, $return, $instance );
			return;
		}
        
		self::register_globals();
        
		$instance['dw_include'] = isset( $instance['dw_include'] ) ? $instance['dw_include'] : 0;
		$instance['dw_logged'] = self::show_logged( $instance );
		$instance['other_ids'] = isset( $instance['other_ids'] ) ? $instance['other_ids'] : '';
?>
<div class="dw_opts">
	<input type="hidden" name="<?php echo esc_attr( $widget->get_field_name('dw_include') ); ?>" id="<?php echo esc_attr( $widget->get_field_id('dw_include') ); ?>" value="<?php echo esc_attr( $instance['dw_include'] ) ?>" />
	<input type="hidden" id="<?php echo esc_attr( $widget->get_field_id('dw_logged') ); ?>" name="<?php echo esc_attr( $widget->get_field_name('dw_logged') ); ?>" value="<?php echo esc_attr( $instance['dw_logged'] ) ?>" />
    
    <?php
	foreach ( $instance as $k => $v ) {
		if ( ! $v ) {
			continue;
		}
            
		if ( strpos( $k, 'page-' ) === 0 || strpos( $k, 'type-' ) === 0 || strpos( $k, 'cat-' ) === 0 || strpos( $k, 'tax-' ) === 0 || strpos( $k, 'lang-' ) === 0 ) {
    ?>
	<input type="hidden" id="<?php echo esc_attr( $widget->get_field_id( $k ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( $k ) ); ?>" value="<?php echo esc_attr( $v ) ?>"  />
    <?php
    	}
    } ?>
    
	<input type="hidden" name="<?php echo esc_attr( $widget->get_field_name('other_ids') ); ?>" id="<?php echo esc_attr( $widget->get_field_id('other_ids') ); ?>" value="<?php echo esc_attr( $instance['other_ids'] ) ?>" />
</div>
<?php
    }

    function show_widget_options() {
		$instance = htmlspecialchars_decode( nl2br( stripslashes( $_POST['opts'] ) ) );
        $instance = json_decode( $instance, true );
		$this->id_base = sanitize_text_field( $_POST['id_base'] );
		$this->number = sanitize_text_field( $_POST['widget_number'] );
        
        $new_instance = array();
        $prefix = 'widget-' . $this->id_base . '[' . $this->number . '][';
        foreach ( $instance as $k => $v ) {
            $n = str_replace( array( $prefix, ']'), '', $v['name'] );
            $new_instance[ $n ] = $v['value'];
        }

        self::show_hide_widget_options( $this, '', $new_instance );
        wp_die();
    }

	function show_hide_widget_options( $widget, $return, $instance ) {
		self::register_globals();
    
		$wp_page_types = self::page_types();
            
		$instance['dw_include'] = isset( $instance['dw_include'] ) ? $instance['dw_include'] : 0;
		$instance['dw_logged'] = self::show_logged( $instance );
		$instance['other_ids'] = isset( $instance['other_ids'] ) ? $instance['other_ids'] : '';
?>   
    ---&gt;&gt;&gt;<span class="dw_toggle">Toggle Display Widgets Options</span>&lt;&lt;&lt;---
    <div class="dw_collapse"> <!-- extra options section -->
    <p>
        <label for="<?php echo esc_attr( $widget->get_field_id('dw_include') ); ?>"><?php _e( 'Show Widget for:', 'display-widgets' ) ?></label>
        <select name="<?php echo esc_attr( $widget->get_field_name('dw_logged') ); ?>" id="<?php echo esc_attr( $widget->get_field_id('dw_logged') ); ?>" class="widefat">
            <option value=""><?php _e( 'Everyone', 'display-widgets' ) ?></option>
            <option value="out" <?php echo selected( $instance['dw_logged'], 'out' ) ?>><?php _e( 'Logged-out users', 'display-widgets' ) ?></option>
            <option value="in" <?php echo selected( $instance['dw_logged'], 'in' ) ?>><?php _e( 'Logged-in users', 'display-widgets' ) ?></option>
        </select>
    </p>

     <p>
    	<select name="<?php echo esc_attr( $widget->get_field_name('dw_include') ); ?>" id="<?php echo esc_attr( $widget->get_field_id('dw_include') ); ?>" class="widefat">
            <option value="0"><?php _e( 'Hide on checked pages', 'display-widgets' ) ?></option>
            <option value="1" <?php echo selected( $instance['dw_include'], 1 ) ?>><?php _e( 'Show on checked pages', 'display-widgets' ) ?></option>
        </select>
    </p>    

<div style="height:150px; overflow:auto; border:1px solid #dfdfdf; padding:5px; margin-bottom:5px;">
    <h4 class="dw_toggle" style="cursor:pointer;margin-top:0;"><?php _e( 'Miscellaneous', 'display-widgets' ) ?> +/-</h4>
    <div class="dw_collapse">
    <?php
	foreach ( $wp_page_types as $key => $label ) {
		$instance['page-'. $key] = isset( $instance[ 'page-' . $key ] ) ? $instance[ 'page-' . $key ] : false;
    ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance[ 'page-' . $key ], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('page-'. $key) ); ?>" name="<?php echo esc_attr( $widget->get_field_name('page-'. $key) ); ?>" />
        <label for="<?php echo esc_attr( $widget->get_field_id('page-'. $key) ); ?>"><?php echo $label; ?></label></p>
    <?php
    } ?>
    </div>
    
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Pages') ?> +/-</h4>
    <div class="dw_collapse">
    <?php 
	foreach ( $this->pages as $page ) {
		$instance[ 'page-' . $page->ID ] = isset( $instance[ 'page-' . $page->ID ] ) ? $instance[ 'page-' . $page->ID ] : false;
	}

	// use custom Page Walker to build page list
	$args = array( 'instance' => $instance, 'widget' => $widget );
	$page_list = $this->page_list->walk( $this->pages, 0, $args );
	if ( $page_list ) {
		echo '<ul>' . $page_list . '</ul>';
	}
    ?>
    </div>
    
    <?php if ( ! empty( $this->cposts ) ) { ?>
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Custom Post Types', 'display-widgets' ) ?> +/-</h4>
    <div class="dw_collapse">
    <?php
		foreach ( $this->cposts as $post_key => $custom_post ) {
			$instance[ 'type-' . $post_key ] = isset( $instance[ 'type-' . $post_key ] ) ? $instance[ 'type-' . $post_key ] : false;
    ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance['type-'. $post_key], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('type-'. $post_key) ); ?>" name="<?php echo esc_attr( $widget->get_field_name('type-'. $post_key) ); ?>" />
		<label for="<?php echo esc_attr( $widget->get_field_id('type-'. $post_key) ); ?>"><?php echo stripslashes( $custom_post->labels->name ) ?></label></p>
    <?php
			unset( $post_key, $custom_post );
        } ?>
    </div>
    <?php // TODO: Change so that if any particular section has no members you can tell and are not surprised when it doesn't expand from collapsed state, maybe easier in javascript? ?>
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Custom Post Type Archives', 'display-widgets' ) ?> +/-</h4>
    <div class="dw_collapse">
	<?php
		foreach ( $this->cposts as $post_key => $custom_post ) {
			if ( ! $custom_post->has_archive ) {
				// don't give the option if there is no archive page
				continue;
			}
			$instance[ 'type-' . $post_key . '-archive' ] = isset( $instance[ 'type-' . $post_key . '-archive' ] ) ? $instance[ 'type-' . $post_key . '-archive' ] : false;
    ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance[ 'type-' . $post_key . '-archive' ], true ); ?> id="<?php echo esc_attr( $widget->get_field_id( 'type-'. $post_key . '-archive' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'type-' . $post_key . '-archive' ) ); ?>" />
		<label for="<?php echo esc_attr( $widget->get_field_id( 'type-' . $post_key . '-archive' ) ); ?>"><?php echo stripslashes( $custom_post->labels->name ) ?> <?php _e( 'Archive', 'display-widgets' ) ?></label></p>
    		<?php 
		} ?>
    </div>
    <?php } // END empty( $this->cposts ) ?>
    
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Categories') ?> +/-</h4>
    <div class="dw_collapse">
		<?php $instance['cat-all'] = isset( $instance['cat-all'] ) ? $instance['cat-all'] : false; ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance['cat-all'], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('cat-all') ); ?>" name="<?php echo esc_attr( $widget->get_field_name('cat-all') ); ?>" />
        <label for="<?php echo $widget->get_field_id('cat-all'); ?>"><?php _e( 'All Categories', 'display-widgets' ); ?></label></p>
    <?php
		foreach ( $this->cats as $cat ) {
        	$instance[ 'cat-' . $cat->cat_ID ] = isset( $instance[ 'cat-' . $cat->cat_ID ] ) ? $instance[ 'cat-' . $cat->cat_ID] : false;
    ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance['cat-'. $cat->cat_ID], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('cat-'. $cat->cat_ID) ); ?>" name="<?php echo esc_attr( $widget->get_field_name('cat-'. $cat->cat_ID) ); ?>" />
        <label for="<?php echo $widget->get_field_id('cat-'. $cat->cat_ID); ?>"><?php echo $cat->cat_name ?></label></p>
    <?php
			unset( $cat );
		}
    ?>
    </div>
    
    <?php if ( ! empty( $this->taxes ) ) { ?>
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Taxonomies', 'display-widgets' ) ?> +/-</h4>
    <div class="dw_collapse">
    <?php
		foreach ( $this->taxes as $tax => $taxname ) {
        	$instance[ 'tax-' . $tax ] = isset( $instance[ 'tax-' . $tax ] ) ? $instance[ 'tax-' . $tax ] : false;
    ?>
		<p><input class="checkbox" type="checkbox" <?php checked( $instance['tax-'. $tax], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('tax-'. $tax) ); ?>" name="<?php echo esc_attr( $widget->get_field_name('tax-'. $tax) ); ?>" />
		<label for="<?php echo esc_attr( $widget->get_field_id('tax-'. $tax) ); ?>"><?php echo str_replace( array( '_','-' ), ' ', ucfirst( $taxname ) ) ?></label></p>
    <?php
			unset( $tax );
		}
    ?>
    </div>
    <?php } ?>
    
    <?php if ( ! empty( $this->langs ) ) { ?>
    <h4 class="dw_toggle" style="cursor:pointer;"><?php _e( 'Languages', 'display-widgets' ) ?> +/-</h4>
    <div class="dw_collapse">
    <?php
		foreach ( $this->langs as $lang ) {
			$key = $lang['language_code'];
			$instance[ 'lang-' . $key ] = isset( $instance[ 'lang-' . $key ] ) ? $instance[ 'lang-' . $key ] : false;
    ?>
        <p><input class="checkbox" type="checkbox" <?php checked( $instance[ 'lang-' . $key ], true ); ?> id="<?php echo esc_attr( $widget->get_field_id('lang-'. $key) ); ?>" name="<?php echo esc_attr( $widget->get_field_name('lang-'. $key) ); ?>" />
        <label for="<?php echo esc_attr( $widget->get_field_id('lang-'. $key) ); ?>"><?php echo $lang['native_name'] ?></label></p>
       
    <?php 
			unset( $lang, $key );
		}
    ?>
    </div>
    <?php } ?>
    
	<p><label for="<?php echo esc_attr( $widget->get_field_id('other_ids') ); ?>"><?php _e( 'Comma Separated list of IDs of posts not listed above', 'display-widgets' ) ?>:</label>
	<input type="text" value="<?php echo esc_attr( $instance['other_ids'] ) ?>" name="<?php echo esc_attr( $widget->get_field_name('other_ids') ); ?>" id="<?php echo esc_attr( $widget->get_field_id('other_ids') ); ?>" />
    </p>
    </div>
<?php
    }

	function update_widget_options( $instance, $new_instance, $old_instance ) {
		self::register_globals();
    
		if ( ! empty( $this->pages ) ) {
			foreach ( $this->pages as $page ) {
				if ( isset( $new_instance[ 'page-' . $page->ID ] ) ) {
					$instance[ 'page-' . $page->ID ] = 1;
				} else if ( isset( $instance[ 'page-' . $page->ID ] ) ) {
					unset( $instance[ 'page-' . $page->ID ] );
				}
				unset( $page );
			}
		}

		if ( isset( $new_instance['cat-all'] ) ) {
			$instance['cat-all'] = 1;

			foreach ( $this->cats as $cat ) {
				if ( isset( $new_instance[ 'cat-' . $cat->cat_ID ] ) ) {
					unset( $instance['cat-' . $cat->cat_ID ] );
				}
			}
		} else {
			unset( $instance['cat-all'] );

			foreach ( $this->cats as $cat ) {
				if ( isset( $new_instance[ 'cat-' . $cat->cat_ID ] ) ) {
					$instance[ 'cat-' . $cat->cat_ID ] = 1;
				} else if ( isset( $instance[ 'cat-' . $cat->cat_ID ] ) ) {
					unset( $instance['cat-' . $cat->cat_ID ] );
				}
				unset( $cat );
			}
		}

		if ( ! empty( $this->cposts ) ) {
			foreach ( $this->cposts as $post_key => $custom_post ) {
				if ( isset( $new_instance[ 'type-' . $post_key ] ) ) {
					$instance['type-'. $post_key] = 1;
				} else if ( isset( $instance['type-' . $post_key ] ) ) {
					unset( $instance[ 'type-' . $post_key ] );
				}

				if ( isset( $new_instance['type-' . $post_key . '-archive' ] ) ) {
					$instance[ 'type-' . $post_key . '-archive' ] = 1;
				} else if ( isset( $instance[ 'type-' . $post_key . '-archive' ] ) ) {
					unset( $instance[ 'type-' . $post_key . '-archive' ] );
				}
                
				unset( $custom_post );
			}
		}

		if ( ! empty( $this->taxes ) ) {
			foreach ( $this->taxes as $tax => $taxname ) {
				if ( isset( $new_instance[ 'tax-' . $tax ] ) ) {
					$instance['tax-'. $tax] = 1;
				} else if ( isset( $instance[ 'tax-' . $tax ] ) ) {
					unset( $instance[ 'tax-' . $tax ] );
				}
				unset( $tax );
			}
		}

		if ( ! empty( $this->langs ) ) {
			foreach ( $this->langs as $lang ) {
				if ( isset( $new_instance[ 'lang-' . $lang['language_code'] ] ) ) {
					$instance[ 'lang-' . $lang['language_code'] ] = 1;
				} else if ( isset( $instance[ 'lang-'. $lang['language_code'] ] ) ) {
					unset( $instance[ 'lang-' . $lang['language_code'] ] ) ;
				}
				unset( $lang );
			}
		}

		$instance['dw_include'] = ( isset( $new_instance['dw_include'] ) && $new_instance['dw_include'] ) ? 1 : 0;
		$instance['dw_logged'] = ( isset( $new_instance['dw_logged'] ) && $new_instance['dw_logged'] ) ? $new_instance['dw_logged'] : '';
		$instance['other_ids'] = ( isset( $new_instance['other_ids'] ) && $new_instance['other_ids'] ) ? $new_instance['other_ids'] : '';
        
		$page_types = self::page_types();
		foreach ( array_keys( $page_types ) as $page ) {
			if ( isset( $new_instance[ 'page-'. $page ] ) ) {
				$instance[ 'page-' . $page ] = 1;
			} else if ( isset( $instance['page-' . $page ] ) ) {
				unset( $instance[ 'page-' . $page ] );
			}
		}
		unset( $page_types );

		return $instance;
	}
    
    function get_field_name( $field_name ) {
		return 'widget-' . $this->id_base . '[' . $this->number . '][' . $field_name . ']';
	}
    
	function get_field_id( $field_name ) {
		return 'widget-' . $this->id_base . '-' . $this->number . '-' . $field_name;
	}
    
    function load_js() {
        global $pagenow;
        
        if ( $pagenow != 'widgets.php' ) {
            //only load the js on the widgets page
            return;
        }
?>
<script type="text/javascript">
/*<![CDATA[*/
jQuery(document).ready(function($){
$('.widgets-holder-wrap').on('click', '.dw_toggle', dw_toggle);
});
jQuery(document.body).bind('click.widgets-toggle', dw_show_opts);
function dw_show_opts(e){
    var target = jQuery(e.target);
    var widget = target.closest('div.widget');
	var inside = widget.children('.widget-inside');
	var opts = inside.find('.dw_opts');
	if(opts.length == 0){
	    return;
	}
	
	inside.find('.spinner').show();
    
    jQuery.ajax({
		type:'POST',url:ajaxurl,
		data:{
		    'action':'dw_show_widget',
		    'opts':JSON.stringify(opts.children('input').serializeArray()),
		    'id_base':inside.find('input.id_base').val(),
		    'widget_number':(inside.find('input.multi_number').val() == '') ? inside.find('input.widget_number').val() : inside.find('input.multi_number').val()
		},
		success:function(html){ opts.replaceWith(html); inside.find('.spinner').hide(); }
	});
}
function dw_toggle(){jQuery(this).next('.dw_collapse').toggle();}
/*]]>*/
</script>
<style type="text/css">
.dw_collapse { /* hide the whole plugin additional section and EACH sub section by default */
   display: none;
}
span.dw_toggle { /* styling for the hide whole plugin button */
   color: #0074a2;
   line-height: 24px;
   text-decoration: underline;
   cursor: pointer;
}
span.dw_toggle:hover {
   color: red;
}
</style>
<?php
    }
    
    function show_logged( $instance ) {
        if ( isset( $instance['dw_logged'] ) ) {
            return $instance['dw_logged'];
        }
        
        if ( isset( $instance['dw_logout'] ) && $instance['dw_logout'] ) {
            $instance['dw_logged'] = 'out';
        } else if ( isset( $instance['dw_login'] ) && $instance['dw_login'] ) {
            $instance['dw_logged'] = 'in';
        } else {
            $instance['dw_logged'] = '';
        }
        
        return $instance['dw_logged'];
    }
    
    function page_types(){
        $page_types = array(
            'front'     => __( 'Front', 'display-widgets' ),
            'home'      => __( 'Blog', 'display-widgets' ),
            'archive'   => __( 'Archives'),
            'single'    => __( 'Single Post'),
            '404'       => '404',
            'search'    => __( 'Search'),
        );
        
        return apply_filters('dw_pages_types_register', $page_types);
    }

	function register_globals(){
		if ( ! empty( $this->checked ) ) {
			return;
		}
        
		$saved_details = get_transient( $this->transient_name );
		if ( $saved_details ) {
			foreach ( $saved_details as $k => $d ) {
				if ( empty( $this->{$k} ) ) {
					$this->{$k} = $d;
				}
                
				unset( $k, $d );
			}
		}
        
		if ( empty( $this->pages ) ) {
			$this->pages = get_posts( array(
				'post_type' => 'page', 'post_status' => 'publish',
				'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC',
				'fields' => array('ID', 'name'),
			));
		}
        
		if ( empty( $this->cats ) ) {
			$category_taxonomy = array( 'category' );
			if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			    $category_taxonomy[] = 'product_cat';
			}
			$this->cats = get_categories( array(
				'hide_empty'    => false,
				'taxonomy'   => $category_taxonomy,
				//'fields'        => 'id=>name', //added in 3.8
			) );
		}
        
		if ( empty( $this->cposts ) ) {
			$this->cposts = get_post_types( array(
				'public' => true,
			), 'object');
            
			foreach ( array( 'revision', 'attachment', 'nav_menu_item' ) as $unset ) {
				unset( $this->cposts[ $unset ] );
			}
            
			foreach ( $this->cposts as $c => $type ) {
				$post_taxes = get_object_taxonomies( $c );
				foreach ( $post_taxes as $post_tax) {
					if ( in_array( $post_tax, array( 'category', 'post_format' ) ) ) {
						continue;
					}
                    
					$taxonomy = get_taxonomy( $post_tax );
					$name = $post_tax;

					if ( isset( $taxonomy->labels->name ) && ! empty( $taxonomy->labels->name ) ) {
						$name = $taxonomy->labels->name;
					}
                    
					$this->taxes[ $post_tax ] = $name;
				}
			}
		}
        
		if ( empty( $this->langs ) && function_exists('icl_get_languages') ) {
			$this->langs = icl_get_languages('skip_missing=0&orderby=code');
		}
        
		// save for one week
		set_transient( $this->transient_name, array(
			'pages'     => $this->pages,
			'cats'      => $this->cats,
			'cposts'    => $this->cposts,
			'taxes'     => $this->taxes,
		), 60*60*24*7 );

		if ( empty( $this->checked ) ) {
			$this->checked[] = true;
		}
	}
    
	function delete_transient() {
		delete_transient( $this->transient_name );
	}

	function load_lang(){
		load_plugin_textdomain( 'display-widgets', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/* WPML support */
	function get_lang_id( $id, $type = 'page' ) {
		if ( function_exists('icl_object_id') ) {
			$id = icl_object_id( $id, $type, true );
		}
    
		return $id;
	}

}

/*
custom Page Walker class
*/
class DW_Walker_Page_List extends Walker_Page {

	function start_lvl( &$output, $depth = 0, $args = array() ) {
		$output .= "\n<ul class='children'>\n";
	}

	function end_lvl( &$output, $depth = 0, $args = array() ) {
		$output .= "</ul>\n";
	}

	function start_el( &$output, $page, $depth = 0, $args = array(), $current_page = 0 ) {
		if ( $depth )
			$indent = str_repeat("&mdash; ", $depth);
		else
			$indent = '';

		// args: $instance, $widget
		extract( $args, EXTR_SKIP );
    

		if ( '' === $page->post_title ) {
			$page->post_title = sprintf( __( '#%d (no title)', 'display-widgets' ), $page->ID );
		}

		$output .= '<li>' . $indent;
		$output .= '<input class="checkbox" type="checkbox" ' . checked( $instance[ 'page-' . $page->ID ], true, false ) . ' id="' . esc_attr( $widget->get_field_id('page-'. $page->ID) ) . '" name="' . esc_attr( $widget->get_field_name('page-'. $page->ID) ) .'" />';

		$output .= '<label for="' . esc_attr( $widget->get_field_id('page-'. $page->ID) ) . '">' . apply_filters( 'the_title', $page->post_title, $page->ID ) . '</label>';
	}

	function end_el( &$output, $page, $depth = 0, $args = array() ) {
		$output .= "</li>\n";
	}

}

new DWPlugin();

/*
custom Page Walker CSS
*/
function dw_widgets_style() {
	echo '<style>';
	// use next line for normal indent instead of &mdash:
	// echo '.dw_collapse ul ul { padding-left: 1.5em; }';
	echo '.dw_collapse li { line-height: 1.5em; margin: 1em 0; }';
	echo '</style>';
}
add_action( 'admin_print_styles-widgets.php', 'dw_widgets_style' );
