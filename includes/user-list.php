<?php

class pw_new_user_approve_user_list {

    /**
     * The only instance of pw_new_user_approve_user_list.
     *
     * @var pw_new_user_approve_user_list
     */
    private static $instance;

    /**
     * Returns the main instance.
     *
     * @return pw_new_user_approve_user_list
     */
    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new pw_new_user_approve_user_list();
        }
        return self::$instance;
    }

    private function __construct() {
        // Actions
        add_action( 'load-users.php', array( $this, 'update_action' ) );
        add_action( 'restrict_manage_users', array( $this, 'status_filter' ) );
        add_action( 'pre_user_query', array( $this, 'filter_by_status' ) );
        add_action( 'admin_footer-users.php', array( $this, 'admin_footer' ) );
        add_action( 'load-users.php', array( $this, 'bulk_action' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        // Filters
        add_filter( 'user_row_actions', array( $this, 'user_table_actions' ), 10, 2 );
        add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'status_column' ), 10, 3 );
    }

    public function update_action() {
        if ( isset( $_GET['new_user_approve_filter'] ) )
            return;

        if ( isset( $_GET['action'] ) && ( in_array( $_GET['action'], array( 'approve', 'deny' ) ) ) ) {
            check_admin_referer( 'new-user-approve' );

            $status = sanitize_key( $_GET['action'] );
            $user = absint( $_GET['user'] );

            pw_new_user_approve()->update_user_status( $user, $status );

            wp_redirect( admin_url( 'users.php' ) );
        }
    }

    public function user_table_actions( $actions, $user ) {
        if ( $user->ID == get_current_user_id() )
            return $actions;

        $user_status = pw_new_user_approve()->get_user_status( $user->ID );

        $approve_link = wp_nonce_url( add_query_arg( array( 'action' => 'approve', 'user' => $user->ID ) ), 'new-user-approve' );
        $deny_link = wp_nonce_url( add_query_arg( array( 'action' => 'deny', 'user' => $user->ID ) ), 'new-user-approve' );

        $approve_action = '<a href="' . esc_url( $approve_link ) . '">' . __( 'Approve', 'new-user-approve' ) . '</a>';
        $deny_action = '<a href="' . esc_url( $deny_link ) . '">' . __( 'Deny', 'new-user-approve' ) . '</a>';

        if ( $user_status == 'pending' ) {
            $actions[] = $approve_action;
            $actions[] = $deny_action;
        } else if ( $user_status == 'approved' ) {
            $actions[] = $deny_action;
        } else if ( $user_status == 'denied' ) {
            $actions[] = $approve_action;
        }

        return $actions;
    }

    public function add_column( $columns ) {
        $the_columns['pw_user_status'] = 'Status';

        $newcol = array_slice( $columns, 0, -1 );
        $newcol = array_merge( $newcol, $the_columns );
        $columns = array_merge( $newcol, array_slice( $columns, 1 ) );

        return $columns;
    }

    public function status_column( $val, $column_name, $user_id ) {
        switch ( $column_name ) {
            case 'pw_user_status' :
                return pw_new_user_approve()->get_user_status( $user_id );
                break;

            default:
        }

        return $val;
    }

    public function status_filter() {
        $filter_button = submit_button( __( 'Filter' ), 'button', 'pw-status-query-submit', false, array( 'id' => 'pw-status-query-submit' ) );
        $filtered_status = (isset( $_GET['new_user_approve_filter'] ) ) ? esc_attr( $_GET['new_user_approve_filter'] ) : '';

        ?>
        <label class="screen-reader-text" for="new_user_approve_filter">View all users</label>
        <select id="new_user_approve_filter" name="new_user_approve_filter" style="float: none; margin: 0 0 0 15px;">
            <option value="">View all users</option>
        <?php foreach ( pw_new_user_approve()->get_valid_statuses() as $status ) : ?>
            <option value="<?php echo esc_attr( $status ); ?>"<?php selected( $status, $filtered_status ); ?>><?php echo esc_html( $status ); ?></option>
        <?php endforeach; ?>
        </select>
        <?php echo apply_filters( 'new_user_approve_filter_button', $filter_button ); ?>
        <style>
            #pw-status-query-submit {
                float: right;
                margin: 2px 0 0 5px;
            }
        </style>
        <?php
    }

    public function filter_by_status( $query ) {
        global $wpdb;

        if ( !is_admin() )
            return;

        $screen = get_current_screen();
        if ( 'users' != $screen->id )
            return;

        if ( isset( $_GET['new_user_approve_filter'] ) ) {
            $filter = esc_attr( $_GET['new_user_approve_filter'] );

            $query->query_from .= " INNER JOIN {$wpdb->usermeta} wp_usermeta ON ( {$wpdb->users}.ID = wp_usermeta.user_id )";

            if ( 'approved' == $filter ) {
                $query->query_fields = "DISTINCT SQL_CALC_FOUND_ROWS {$wpdb->users}.ID";
                $query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS mt1 ON ({$wpdb->users}.ID = mt1.user_id AND mt1.meta_key = 'pw_user_status')";
                $query->query_where .= " AND ( ( wp_usermeta.meta_key = 'pw_user_status' AND CAST(wp_usermeta.meta_value AS CHAR) = 'approved' ) OR mt1.user_id IS NULL )";
            } else {
                $query->query_where .= " AND ( (wp_usermeta.meta_key = 'pw_user_status' AND CAST(wp_usermeta.meta_value AS CHAR) = '{$filter}') )";
            }
        }
    }

    public function admin_footer() {
        $screen = get_current_screen();

        if ( $screen->id == 'users' ) : ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('<option>').val('approve').text('<?php _e( 'Approve', 'new-user-approve' )?>').appendTo("select[name='action']")
                    $('<option>').val('approve').text('<?php _e( 'Approve', 'new-user-approve' )?>').appendTo("select[name='action2']");

                    $('<option>').val('deny').text('<?php _e( 'Deny', 'new-user-approve' )?>').appendTo("select[name='action']")
                    $('<option>').val('deny').text('<?php _e( 'Deny', 'new-user-approve' )?>').appendTo("select[name='action2']");
                });
            </script>
        <?php endif;
    }

    public function bulk_action() {
        $screen = get_current_screen();

        if ( $screen->id == 'users' ) {

            // get the action
            $wp_list_table = _get_list_table( 'WP_Users_List_Table' );
            $action = $wp_list_table->current_action();

            $allowed_actions = array( 'approve', 'deny' );
            if ( !in_array( $action, $allowed_actions ) )
                return;

            // security check
            check_admin_referer( 'bulk-users' );

            // make sure ids are submitted
            if ( isset( $_REQUEST['users'] ) ) {
                $user_ids = array_map( 'intval', $_REQUEST['users'] );
            }

            if ( empty( $user_ids ) )
                return;

            $sendback = remove_query_arg( array( 'approved', 'denied', 'deleted', 'ids' ), wp_get_referer() );
            if ( ! $sendback )
                $sendback = admin_url( "users.php" );

            $pagenum = $wp_list_table->get_pagenum();
            $sendback = add_query_arg( 'paged', $pagenum, $sendback );

            switch($action) {
                case 'approve':
                    $approved = 0;
                    foreach( $user_ids as $user_id ) {
                        pw_new_user_approve()->approve_user( $user_id );
                        $approved++;
                    }

                    $sendback = add_query_arg( array( 'approved' => $approved, 'ids' => join(',', $user_ids ) ), $sendback );
                    break;

                case 'deny':
                    $denied = 0;
                    foreach( $user_ids as $user_id ) {
                        pw_new_user_approve()->deny_user( $user_id );
                        $denied++;
                    }

                    $sendback = add_query_arg( array( 'denied' => $denied, 'ids' => join(',', $user_ids ) ), $sendback );
                    break;

                default: return;
            }

            $sendback = remove_query_arg( array('action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'), $sendback );

            wp_redirect( $sendback );
            exit();
        }
    }

    public function admin_notices() {
        $screen = get_current_screen();

        if ( $screen->id != 'users' )
            return;

        $message = null;

        if ( isset( $_REQUEST['denied'] ) && (int) $_REQUEST['denied']) {
            $message = sprintf( _n( 'User denied.', '%s users denied.', 'new-user-approve' ), number_format_i18n( $_REQUEST['denied'] ) );
        }

        if ( isset( $_REQUEST['approved'] ) && (int) $_REQUEST['approved']) {
            $message = sprintf( _n( 'User approved.', '%s users approved.', 'new-user-approve' ), number_format_i18n( $_REQUEST['approved'] ) );
        }

        if ( !empty( $message ) ) {
            echo '<div class="updated"><p>' . $message . '</p></div>';
        }
    }

}

function pw_new_user_approve_user_list() {
    return pw_new_user_approve_user_list::instance();
}

pw_new_user_approve_user_list();
