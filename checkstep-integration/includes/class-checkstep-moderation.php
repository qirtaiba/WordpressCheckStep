<?php
/**
 * CheckStep Moderation Handler Class
 *
 * @package CheckStep_Integration
 * @since 1.0.0
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class CheckStep_Moderation
 *
 * Handles content moderation via CheckStep API
 */
class CheckStep_Moderation extends BP_Moderation_Abstract {

    /**
     * Item type
     *
     * @var string
     */
    public static $moderation_type = 'checkstep_content';

    /**
     * CheckStep API instance
     *
     * @var CheckStep_API
     */
    private $api;

    /**
     * Constructor
     *
     * @param CheckStep_API $api CheckStep API instance
     */
    public function __construct($api) {
        $this->api = $api;
        parent::$moderation[self::$moderation_type] = self::class;
        $this->item_type = self::$moderation_type;

        /**
         * Moderation code should not add for WordPress backend and if Bypass argument passed for admin
         */
        if ((is_admin() && !wp_doing_ajax()) || self::admin_bypass_check()) {
            return;
        }

        $this->setup_hooks();
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Register webhook endpoints
        add_action('rest_api_init', array($this, 'register_webhooks'));

        // Handle moderation decisions
        add_action('checkstep_handle_decision', array($this, 'handle_moderation_decision'));

        // Filter content display
        add_filter('bp_activity_get_where_conditions', array($this, 'filter_moderated_content'), 10, 2);
        add_filter('bp_forums_get_where_conditions', array($this, 'filter_moderated_content'), 10, 2);
    }

    /**
     * Register webhook endpoints
     */
    public function register_webhooks() {
        register_rest_route('checkstep/v1', '/decisions', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_decision_webhook'),
            'permission_callback' => array($this, 'verify_webhook'),
            'args' => array(
                'decision_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'content_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'action' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('delete', 'hide', 'warn', 'ban_user'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'reason' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Verify webhook request
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function verify_webhook($request) {
        $signature = $request->get_header('X-CheckStep-Signature');
        $webhook_secret = get_option('checkstep_webhook_secret');

        if (!$signature) {
            return new WP_Error(
                'missing_signature',
                'Missing CheckStep signature header',
                array('status' => 401)
            );
        }

        if (!$webhook_secret) {
            return new WP_Error(
                'missing_secret',
                'Webhook secret not configured',
                array('status' => 500)
            );
        }

        $payload = $request->get_body();
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'invalid_signature',
                'Invalid CheckStep signature',
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Handle incoming decision webhook
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_decision_webhook($request) {
        $params = $request->get_params();

        // Parameters are already validated and sanitized by register_rest_route args
        $decision_id = $params['decision_id'];
        $content_id = $params['content_id'];
        $action = $params['action'];
        $reason = isset($params['reason']) ? $params['reason'] : '';

        // Schedule async processing
        wp_schedule_single_event(
            time(),
            'checkstep_handle_decision',
            array(
                array(
                    'decision_id' => $decision_id,
                    'content_id' => $content_id,
                    'action' => $action,
                    'reason' => $reason,
                )
            )
        );

        return new WP_REST_Response(
            array(
                'status' => 'queued',
                'decision_id' => $decision_id,
            ),
            202
        );
    }

    /**
     * Handle moderation decision
     *
     * @param array $decision_data Decision data from CheckStep
     */
    public function handle_moderation_decision($decision_data) {
        $content_id = absint($decision_data['content_id']);
        $action = sanitize_text_field($decision_data['action']);
        $reason = sanitize_text_field($decision_data['reason']);

        if (!$content_id) {
            return;
        }

        switch ($action) {
            case 'delete':
                $this->delete_content($content_id);
                break;

            case 'hide':
                $this->hide_content($content_id);
                break;

            case 'warn':
                if ($reason) {
                    $this->add_content_warning($content_id, $reason);
                }
                break;

            case 'ban_user':
                $user_id = $this->get_content_owner_id($content_id);
                if ($user_id) {
                    $this->ban_user($user_id);
                }
                break;
        }

        /**
         * Action fired after a CheckStep moderation decision is handled
         *
         * @param array $decision_data The decision data
         * @param string $action The action taken
         * @param int $content_id The content ID
         */
        do_action('checkstep_decision_handled', $decision_data, $action, $content_id);
    }

    /**
     * Filter moderated content
     *
     * @param string $where_sql Current WHERE clause
     * @param array  $args Query arguments
     * @return string
     */
    public function filter_moderated_content($where_sql, $args = array()) {
        global $wpdb;

        if (isset($args['moderation_query']) && false === $args['moderation_query']) {
            return $where_sql;
        }

        // Add conditions to exclude moderated content
        $moderated_content = $wpdb->get_col($wpdb->prepare(
            "SELECT item_id FROM {$wpdb->prefix}bp_moderation WHERE item_type = %s AND hidden = 1",
            self::$moderation_type
        ));

        if (!empty($moderated_content)) {
            $where_sql .= " AND t.id NOT IN (" . implode(',', array_map('absint', $moderated_content)) . ")";
        }

        return $where_sql;
    }

    /**
     * Get permalink
     *
     * @param int $content_id Content ID
     * @return string
     */
    public static function get_permalink($content_id) {
        return get_permalink(absint($content_id));
    }

    /**
     * Get content owner id
     *
     * @param int $content_id Content ID
     * @return int
     */
    public static function get_content_owner_id($content_id) {
        $post = get_post(absint($content_id));
        return $post ? absint($post->post_author) : 0;
    }

    /**
     * Delete content
     *
     * @param int $content_id Content ID
     */
    private function delete_content($content_id) {
        $content_id = absint($content_id);
        if ($content_id) {
            wp_delete_post($content_id, true);
        }
    }

    /**
     * Hide content
     *
     * @param int $content_id Content ID
     */
    private function hide_content($content_id) {
        $content_id = absint($content_id);
        if ($content_id) {
            wp_update_post(array(
                'ID' => $content_id,
                'post_status' => 'private'
            ));
        }
    }

    /**
     * Add content warning
     *
     * @param int    $content_id Content ID
     * @param string $warning Warning message
     */
    private function add_content_warning($content_id, $warning) {
        $content_id = absint($content_id);
        $warning = sanitize_text_field($warning);
        if ($content_id && $warning) {
            wp_set_object_terms($content_id, $warning, 'content-warning', true);
        }
    }

    /**
     * Ban user
     *
     * @param int $user_id User ID
     */
    private function ban_user($user_id) {
        $user_id = absint($user_id);
        if ($user_id && function_exists('bp_moderation_add')) {
            bp_moderation_add(array(
                'user_id' => $user_id,
                'type' => 'user',
            ));
        }
    }
}