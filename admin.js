  jQuery(function ($) {

     var sbcom_submit = $('#sbwcom_submit_merge');

     sbcom_submit.on('click', function (e) {
        e.preventDefault();

        var specified_order_no = $('#sbwcom_order_number_dd').val();
        var curr_order_id = $('#sboma_curr_order_no').val();

        if (specified_order_no != '') {

           var merge_data = {
              'action': 'sboma_process_order_edit_screen_merge',
              'current_order_id': curr_order_id,
              'specified_order_no': specified_order_no
           };

           $.post(ajaxurl, merge_data, function (response) {
              alert(response);
           });

        }
        else {
           window.alert('Please specify an order number to merge with!');
        }
     });
  });

