<?php
// add the code snippet in functions.php
function modify_search_query_order($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_search()) {
        global $wpdb;
        $keyword = trim($query->get('s'));

        $query->set('post_type', array('page'));

        if (empty($keyword)) {
            return; // Prevent errors if search keyword is empty
        }

        // Modify the WHERE clause (Only search in post titles)
        add_filter('posts_where', function ($where) use ($wpdb, $keyword) {
            $keyword_sanitized = esc_sql($wpdb->esc_like($keyword));
            return $where . " AND {$wpdb->posts}.post_title LIKE '%$keyword_sanitized%'";
        });

        // Modify the ORDER BY clause (Only prioritize titles that start with the keyword, then sort by last modified)
        add_filter('posts_orderby', function ($orderby) use ($wpdb, $keyword) {
            $keyword_sanitized = esc_sql($wpdb->esc_like($keyword));
            return "
                CASE 
                    WHEN {$wpdb->posts}.post_title LIKE '{$keyword_sanitized}%' THEN 1  /* Starts With */
                    ELSE 2  /* Other Matches */
                END, {$wpdb->posts}.post_modified DESC  /* Sort by Last Modified */
            ";
        });

        // Cleanup after query execution
        add_action('wp', function () {
            remove_filter('posts_where', '__return_false');
            remove_filter('posts_orderby', '__return_false');
        });
    }
}
add_action('pre_get_posts', 'modify_search_query_order');
