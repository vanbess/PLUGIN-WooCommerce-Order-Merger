<?php
  /*
   * Plugin Name: Silverback Woo Order Merge
   * Plugin URI: https://silverbackdev.co.za
   * Description: Plugin to merge two WooCommerce orders into one
   * Author: Werner C. Bessinger
   * Version: 1.0.0
   * Author URI: https://silverbackdev.co.za
   */

  /* PREVENT DIRECT ACCESS */
  if (!defined('ABSPATH')):
      exit;
  endif;

  // define plugin path constant
  define('SBOMA_PATH', plugin_dir_path(__FILE__));
  define('SBOMA_URL', plugin_dir_url(__FILE__));

  /* REGISTER SCRIPTS */
  add_action('admin_enqueue_scripts', 'sboma_scripts');
  function sboma_scripts() {
      wp_register_script('sboma_js', SBOMA_URL . 'admin.js', ['jquery'], '1.0.0', true);
      wp_register_style('sboma_css', SBOMA_URL . 'admin.css');
  }

  /* REGISTER MERGED ORDER STATUS */
  function sboma_register_merged_order_status() {
      register_post_status('wc-order-merged', array(
          'label'                     => 'Merged',
          'public'                    => true,
          'exclude_from_search'       => false,
          'show_in_admin_all_list'    => true,
          'show_in_admin_status_list' => true,
          'label_count'               => _n_noop('Merged orders (%s)', 'Merged orders (%s)')
      ));
  }

  add_action('init', 'sboma_register_merged_order_status');

  /* ADD ORDER MERGED STATUSES TO LIST OF EXISTING ORDER STATUSES */
  function sboma_add_merged_status_to_order_statuses($order_statuses) {
      $new_order_statuses = array();

      // add new order status after processing
      foreach ($order_statuses as $key => $status) {
          $new_order_statuses[$key] = $status;
          if ('wc-processing' === $key) {
              $new_order_statuses['wc-order-merged'] = 'Merged';
          }
      }

      return $new_order_statuses;
  }

  add_filter('wc_order_statuses', 'sboma_add_merged_status_to_order_statuses');

  /* ADD ORDER SCREEN MERGE BULK ACTION */
  function sboma_merge_order_bulk_action($bulk_actions) {
      $bulk_actions['merge_orders'] = __('Merge Orders', 'merge_orders');
      return $bulk_actions;
  }

  add_action('bulk_actions-edit-shop_order', 'sboma_merge_order_bulk_action');

  /* FUNCTION TO PROCESS BULK ACTION ORDER MERGE */
  function sboma_process_bulk_action_merge($post_ids) {
      /* order items array */
      $order_product_ids = [];

      /* set order ids */
      $order_ids = $post_ids;

      /* set order id to which products should be merged */
      $merge_to_order_id = $order_ids[1];

      /* set order id from which products should be merged */
      $merge_from_order_id = $order_ids[0];

      /* get products from order to be merged from  */
      $merge_from_order_data = wc_get_order($merge_from_order_id);

      foreach ($merge_from_order_data->get_items() as $key => $item) :
          /* get variation id if order item is variation, else get product id and push to product id array */
          if ($item->get_variation_id() != 0):
              /* check if variation exists in order product ids array
               * if true, add product qty to variation id, else add variation to array */
              if (key_exists($item->get_variation_id(), $order_product_ids)):
                  $order_product_ids[$item->get_variation_id()] += $item->get_quantity();
              else:
                  $order_product_ids[$item->get_variation_id()] = $item->get_quantity();
              endif;
          else:
              /* as with variations */
              if (key_exists($item->get_product_id(), $order_product_ids)):
                  $order_product_ids[$item->get_product_id()] += $item->get_quantity();
              else:
                  $order_product_ids[$item->get_product_id()] = $item->get_quantity();
              endif;
          endif;
      endforeach;

      /* instantiate WC order class */
      $merged_order_data = new WC_Order($merge_to_order_id);

      /* add merged products to merged order */
      foreach ($order_product_ids as $product_id => $qty) :
          $merged_product_data = wc_get_product($product_id);
          $merged_order_data->add_product($merged_product_data, $qty);
      endforeach;

      /* recalculate/update merged order total */
      $merged_order_data->calculate_totals();

      /* update order status of order from which products were merged */
      wp_update_post(['ID' => $merge_from_order_id, 'post_status' => 'wc-order-merged', 'meta_key' => '_is_merged_order', 'meta_value' => true]);
  }

  /* HANDLE MERGE BULK ACTION SUBMISSION */
  function sboma_handle_bulk_action_merge($redirect_to, $doaction, $post_ids) {
      if ($doaction !== 'merge_orders') {
          return $redirect_to;
      }

      /* only allow merging of 2 orders at a time */
      if (count($post_ids) > 2):
          return;
      else:
          sboma_process_bulk_action_merge($post_ids);
      endif;

      $redirect_to = add_query_arg('shop_orders', count($post_ids), $redirect_to);
      return $redirect_to;
  }

  add_filter('handle_bulk_actions-edit-shop_order', 'sboma_handle_bulk_action_merge', 10, 3);

  /* FUNCTION TO PROCESS ORDER EDIT SCREEN MERGE VIA AJAX */
  add_action('wp_ajax_sboma_process_order_edit_screen_merge', 'sboma_process_order_edit_screen_merge', 0);
  add_action('wp_ajax_nopriv_sboma_process_order_edit_screen_merge', 'sboma_process_order_edit_screen_merge', 0);
  function sboma_process_order_edit_screen_merge() {

      /* get submitted data */
      $current_order_id   = $_POST['current_order_id'];
      $specified_order_no = $_POST['specified_order_no'];

      /* order product id arr */
      $order_product_ids = [];

      /* get order id for submitted order number */
      $ordq = new WP_Query(['post_type' => 'shop_order', 'post_status' => 'wc-processing', 'meta_key' => '_order_number_formatted', 'meta_value' => $specified_order_no]);

      if ($ordq->have_posts()):
          while ($ordq->have_posts()):$ordq->the_post();
              $specified_order_id = get_the_ID();
          endwhile;
          wp_reset_postdata();
      else:
          echo __('Order not found. Please specify a valid order number.');
          wp_die();
      endif;

      /* if specified order id smaller than current order id */
      if ($specified_order_id < $current_order_id):

          $curr_order_data = wc_get_order($current_order_id);

          foreach ($curr_order_data->get_items() as $key => $item) :
              /* get variation id if order item is variation, else get product id and push to product id array */
              if ($item->get_variation_id() != 0):
                  /* check if variation exists in order product ids array
                   * if true, add product qty to variation id, else add variation to array */
                  if (key_exists($item->get_variation_id(), $order_product_ids)):
                      $order_product_ids[$item->get_variation_id()] += $item->get_quantity();
                  else:
                      $order_product_ids[$item->get_variation_id()] = $item->get_quantity();
                  endif;
              else:
                  /* as with variations */
                  if (key_exists($item->get_product_id(), $order_product_ids)):
                      $order_product_ids[$item->get_product_id()] += $item->get_quantity();
                  else:
                      $order_product_ids[$item->get_product_id()] = $item->get_quantity();
                  endif;
              endif;
          endforeach;

          /* get current order data */
          $spec_order_data = wc_get_order($specified_order_id);

          /* add merged products to older order */
          foreach ($order_product_ids as $product_id => $qty) :
              $merge_product_data = wc_get_product($product_id);
              $spec_order_data->add_product($merge_product_data, $qty);
          endforeach;

          /* recalculate order totals */
          $totals_recalced = $spec_order_data->calculate_totals();

          if ($totals_recalced):
              wp_update_post(['ID' => $current_order_id, 'post_status' => 'wc-order-merged']);
              echo __('Orders successfully merged', 'woocommerce');
          endif;


      /* else if current order id smaller than specified order id */
      else:

          $spec_order_data = wc_get_order($specified_order_id);

          foreach ($spec_order_data->get_items() as $key => $item) :
              /* get variation id if order item is variation, else get product id and push to product id array */
              if ($item->get_variation_id() != 0):
                  /* check if variation exists in order product ids array
                   * if true, add product qty to variation id, else add variation to array */
                  if (key_exists($item->get_variation_id(), $order_product_ids)):
                      $order_product_ids[$item->get_variation_id()] += $item->get_quantity();
                  else:
                      $order_product_ids[$item->get_variation_id()] = $item->get_quantity();
                  endif;
              else:
                  /* as with variations */
                  if (key_exists($item->get_product_id(), $order_product_ids)):
                      $order_product_ids[$item->get_product_id()] += $item->get_quantity();
                  else:
                      $order_product_ids[$item->get_product_id()] = $item->get_quantity();
                  endif;
              endif;
          endforeach;

          /* get current order data */
          $curr_order_data = wc_get_order($current_order_id);

          /* add merged products to older order */
          foreach ($order_product_ids as $product_id => $qty) :
              $merge_product_data = wc_get_product($product_id);
              $curr_order_data->add_product($merge_product_data, $qty);
          endforeach;

          /* recalculate order totals */
          $totals_recalced = $curr_order_data->calculate_totals();

          if ($totals_recalced):
              wp_update_post(['ID' => $specified_order_id, 'post_status' => 'wc-order-merged']);
              echo __('Orders successfully merged', 'woocommerce');
          endif;

      endif;

      wp_die();
  }

  /* RENDER ORDER EDIT SCREEN META BOX */
  function sboma_display_order_edit_merge() {
      /* get current order id */
      $current_order_id = get_the_ID();
      ?>

      <label id="sbwcom_order_number_dd_label" for="sbwcom_order_number_dd"><?php echo __('Specify order number to merge with:', 'woocommerce'); ?></label>

      <input id="sbwcom_order_number_dd" name="sbwcom_order_number_dd" type="text" placeholder="<?php _e('Order number eg ND1234', 'woocommerce') ?>">

      <input id="sboma_curr_order_no" name="sboma_curr_order_no" value="<?php echo $current_order_id; ?>" type="hidden">

      <!-- submit -->
      <button id="sbwcom_submit_merge" class="button button-primary" style="display: block;"><?php echo __('Merge Orders', 'woocommerce'); ?></button>

      <!-- orders merged overlay -->
      <div id="sbwcom_overlay" style="display: none;"></div>

      <!-- orders merged dialogue -->
      <div id="sbwcom_dialogue" style="display: none;">
        <p><?php echo __('Orders successfully merged.', 'woocommerce'); ?></p>
        <button id="sbwcom_dialogue_close" redirect_to="<?php echo admin_url('edit.php?post_type=shop_order'); ?>"  class="button save_order button-primary"><?php echo __('Understood', 'woocommerce'); ?></button>
      </div>
      <?php
      wp_enqueue_script('sboma_js');
      wp_enqueue_style('sboma_css');
  }

  /* ADD ORDER MERGE OPTION TO ORDER EDIT SCREEN */
  function sboma_merge_order_edit_screen() {

      if (get_post_type() == 'shop_order'):

          /* get current order id */
          $current_order_id = $_GET['post'];

          /* get order merged status */
          $merged_status = get_post_meta($current_order_id, '_is_merged_order', true);

          /* get order status */
          $order_data   = new WC_Order($current_order_id);
          $order_status = $order_data->get_status();

          /* only show merge dropdown if order status is equal to processing AND order has not been merged before */
          if ($order_status == 'processing' && !$merged_status):
              add_meta_box('sboma-order-merge', __('Merge order', 'woocommerce'), 'sboma_display_order_edit_merge', 'shop_order', 'side', 'high');
          endif;
      endif;
  }

  add_action('add_meta_boxes', 'sboma_merge_order_edit_screen');
