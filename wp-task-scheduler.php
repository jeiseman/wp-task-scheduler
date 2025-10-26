<?php
/**
 * Plugin Name: My Task Runner
 * Description: A plugin to demonstrate running a task via AJAX.
 */

// Load and "localize" our JavaScript file
add_action( 'wp_enqueue_scripts', 'my_task_enqueue_scripts' );
function my_task_enqueue_scripts() {
    
    // Register the script
    wp_register_script(
        'my-task-script',
        plugin_dir_url( __FILE__ ) . 'js/my-task.js', // Path to our JS file
        array(), // Dependencies
        '1.0.0', // Version
        true // Load in footer
    );

    // Pass data from PHP to our JavaScript file
    // This is a crucial step!
    wp_localize_script(
        'my-task-script',
        'my_task_ajax', // Object name to use in JS
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ), // WordPress AJAX URL
            'nonce'    => wp_create_nonce( 'my_task_nonce' ) // Security nonce
        )
    );

    // Only load the script if our shortcode is present on the page
    if ( is_singular() && has_shortcode( get_post( get_the_ID() )->post_content, 'my_task_page' ) ) {
        wp_enqueue_script( 'my-task-script' );
    }
}

function my_task_reset_handler() {
    check_ajax_referer( 'my_task_nonce', 'security' );
    
    $date_range = sanitize_text_field( $_POST['daterange'] );
    if ( empty( $date_range ) ) {
        wp_send_json_error( array( 'message' => 'Date range is missing.' ) );
    }

    $cache_key = 'daterange-' . $date_range;
    $status_key = 'status-' . $cache_key;

    // --- CHANGED: Delete the two transients ---
    delete_transient( $cache_key );
    delete_transient( $status_key );
    
    wp_send_json_success( array( 'message' => 'Task cache has been cleared.' ) );
}
function my_task_status_check_handler() {
    check_ajax_referer( 'my_task_nonce', 'security' );

    $date_range = sanitize_text_field( $_POST['daterange'] );
    if ( empty( $date_range ) ) {
        wp_send_json_error( array( 'message' => 'Date range is missing.' ) );
    }

    $cache_key = 'daterange-' . $date_range;
    $status_key = 'status-' . $date_range;
    
    // --- CHANGED: Check for the *cache* transient first ---
    $cached_html = get_transient( $cache_key );
    if ( $cached_html !== false ) {
        // Task is complete and cached!
        wp_send_json_success( array( 
            'status'     => 'complete',
            'html'       => $cached_html 
        ) );
    } 
    
    // --- CHANGED: If no cache, check for the *status* transient ---
    else if ( get_transient( $status_key ) ) {
        // Task is still running
        wp_send_json_success( array( 
            'status' => 'running'
        ) );
    } 
    
    else {
        // No cache and no run lock, it must be pending
        wp_send_json_success( array( 
            'status' => 'pending'
        ) );
    }
}
function my_task_cron_exec( $args ) {
    
    // ... (Argument extraction is the same) ...
    $date_range = $args['date_range'] ?? '';
    $cache_key  = $args['cache_key']  ?? '';
    $status_key = $args['status_key'] ?? '';

    if ( empty( $date_range ) || empty( $cache_key ) || empty( $status_key ) ) {
        return; 
    }

    // ... (Your long-running task and $results_string are the same) ...
    sleep( 10 ); 
    $results_string = "Report for '{$date_range}' generated on: " . date('Y-m-d H:i:s') . "\n";
    $results_string .= "1,234 entries processed.\n";

    // ... (Your HTML generation is the same) ...
    $final_html = my_task_generate_cached_html( $date_range, $results_string );

    // --- CHANGED: Save the final HTML as a transient ---
    // Set it to expire after 1 day
    set_transient( $cache_key, $final_html, 1 * DAY_IN_SECONDS ); 

    // --- CHANGED: Delete the "running" status lock ---
    delete_transient( $status_key );
}
function my_task_schedule_handler() {
    check_ajax_referer( 'my_task_nonce', 'security' );

    $date_range = sanitize_text_field( $_POST['daterange'] );
    if ( empty( $date_range ) ) {
        wp_send_json_error( array( 'message' => 'Date range is missing.' ) );
    }

    $cache_key = 'daterange-' . $date_range;
    $status_key = 'status-' . $cache_key;

    // CHANGED: Use get_transient()
    if ( get_transient( $status_key ) ) {
        wp_send_json_error( array( 'message' => 'This task is already running.' ) );
    }

    // --- CHANGED: Set a "running" transient with a 1-hour expiration ---
    // This is a "lock" to prevent duplicate runs. It expires in 1 hour.
    set_transient( $status_key, 'running', 1 * HOUR_IN_SECONDS );
    
    // Schedule the task (this is unchanged)
    $args = array(
        'date_range' => $date_range,
        'cache_key'  => $cache_key,
        'status_key' => $status_key
    );
    wp_schedule_single_event( time(), 'my_actual_task_hook', $args );
    
    wp_send_json_success( array( 'message' => 'Task has been scheduled! It is now running in the background.' ) );
}
add_shortcode( 'my_task_page', 'my_task_page_shortcode' );
function my_task_page_shortcode( $atts ) {
    
    // --- NEW: Process shortcode attributes ---
    $atts = shortcode_atts(
        array(
            'daterange' => '', // e.g., "2025-01-01_2025-01-31"
        ),
        $atts,
        'my_task_page'
    );
    $date_range = sanitize_text_field( $atts['daterange'] );
    if ( empty( $date_range ) ) {
        return '<p>Error: A "daterange" parameter is required.</p>';
    }

    $cache_key = 'daterange-' . $date_range;
    $status_key = 'status-' . $cache_key;

    // --- CACHE CHECK ---
    // CHANGED: Use get_transient()
    $cached_html = get_transient( $cache_key ); 
    if ( $cached_html !== false ) {
        // Cache exists! Return it directly.
        return str_replace('<div class="task-runner-wrapper">', '<div class="task-runner-wrapper" data-daterange="' . esc_attr($date_range) . '">', $cached_html);
    }

    // --- No cache found, check status ---
    // CHANGED: Use get_transient()
    $task_status = get_transient( $status_key ) ? 'running' : 'pending';

// Prepare styles based on status
    $form_style        = ( $task_status == 'pending' ) ? 'display: block;' : 'display: none;';
    $run_btn_style     = ( $task_status == 'pending' ) ? 'display: inline-block;' : 'display: none;';
    $running_msg_style = ( $task_status == 'running' ) ? 'display: block;' : 'display: none;';
    // 'complete' state is handled by the cache check above, but we leave this for clarity
    $show_btn_style    = 'display: none;'; 
    $reset_btn_style   = 'display: none;';

    // Buffer output for the "pending" or "running" state
    ob_start();
    ?>

    <div class="task-runner-wrapper" data-daterange="<?php echo esc_attr( $date_range ); ?>">
    
        <form id="my-task-form" style="<?php echo esc_attr( $form_style ); ?>">
            <p>Ready to generate report for <strong><?php echo esc_html( $date_range ); ?></strong>.</p>
            <input type="hidden" id="task_daterange" value="<?php echo esc_attr( $date_range ); ?>">
        </form>
        
        <button id="run-task-btn" class="button button-primary" style="<?php echo esc_attr( $run_btn_style ); ?>">
            Generate Report
        </button>

        <div id="task-running-message" class="notice notice-warning inline" style="<?php echo esc_attr( $running_msg_style ); ?>">
            <p><strong>A task is currently running for <?php echo esc_html( $date_range ); ?>.</strong> The page will update automatically when complete.</p>
        </div>

        <button id="show-results-btn" class="button" style="<?php echo esc_attr( $show_btn_style ); ?>"></button>
        <button id="reset-task-btn" class="button button-secondary" style="<?php echo esc_attr( $reset_btn_style ); ?>"></button>

        <div id="task-status-message" style="margin-top: 15px;"></div>
        <div id="task-results-container" style="display: none;"></div>
    </div>

    <?php
    return ob_get_clean();
}
// --- NEW HELPER FUNCTION ---
/**
 * Generates the *final* HTML for the "complete" state.
 * This is what gets cached.
 */
function my_task_generate_cached_html( $date_range, $task_results_string ) {
    // This function replicates the "complete" state of the shortcode.
    ob_start();
    ?>
    <div class="task-runner-wrapper">
    
        <form id="my-task-form" style="display: none;">
            <input type="hidden" id="task_daterange" value="<?php echo esc_attr( $date_range ); ?>">
        </form>
        
        <br>
        
        <button id="run-task-btn" class="button button-primary" style="display: none;">
            Generate Report
        </button>

        <div id="task-running-message" class="notice notice-warning inline" style="display: none;"></div>

        <button id="show-results-btn" class="button" style="display: inline-block;">
            Show Task Results
        </button>
        <button id="reset-task-btn" class="button button-secondary" style="display: inline-block; margin-left: 10px;">
            Reset Task
        </button>

        <div id="task-status-message" style="margin-top: 15px;">Task is complete.</div>
        
        <div id="task-results-container" style="display: none; margin-top: 20px; padding: 10px; border: 1px solid #ccc; background: #f9f9f9;">
            <h4>Task Results:</h4>
            <pre><?php echo esc_html( $task_results_string ); ?></pre>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
// 2. Load and "localize" our JavaScript file
add_action( 'wp_enqueue_scripts', 'my_task_enqueue_scripts' );
function my_task_enqueue_scripts() {
    
    // Register the script
    wp_register_script(
        'my-task-script',
        plugin_dir_url( __FILE__ ) . 'js/my-task.js', // Path to our JS file
        array(), // Dependencies
        '1.0.0', // Version
        true // Load in footer
    );

    // Pass data from PHP to our JavaScript file
    // This is a crucial step!
    wp_localize_script(
        'my-task-script',
        'my_task_ajax', // Object name to use in JS
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ), // WordPress AJAX URL
            'nonce'    => wp_create_nonce( 'my_task_nonce' ) // Security nonce
        )
    );

    // Only load the script if our shortcode is present on the page
    if ( is_singular() && has_shortcode( get_post( get_the_ID() )->post_content, 'my_task_page' ) ) {
        wp_enqueue_script( 'my-task-script' );
    }
}
