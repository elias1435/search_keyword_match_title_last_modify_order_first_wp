<?php
// ===== Title + Tag Search (handles & vs &amp;) =====

// Main query setup
add_action('pre_get_posts', function ($q) {
  if (is_admin() || !$q->is_main_query() || !$q->is_search()) return;

  $q->set('post_type', 'post');
  $q->set('ignore_sticky_posts', true);

  // Force newest first (some builders override this)
  $q->set('orderby', 'date');
  $q->set('order', 'DESC');
}, 999);

// Join tags (kept from your code)
add_filter('posts_join', function ($join, $q) {
  if (is_admin() || !$q->is_search()) return $join;
  global $wpdb;

  if (strpos($join, 'tt_rel') === false) {
    $join .= " LEFT JOIN $wpdb->term_relationships AS tt_rel ON ($wpdb->posts.ID = tt_rel.object_id) ";
    $join .= " LEFT JOIN $wpdb->term_taxonomy AS tt_tax ON (tt_tax.term_taxonomy_id = tt_rel.term_taxonomy_id AND tt_tax.taxonomy = 'post_tag') ";
    $join .= " LEFT JOIN $wpdb->terms AS tt_terms ON (tt_terms.term_id = tt_tax.term_id) ";
  }
  return $join;
}, 10, 2);

// Helper: normalize a token into variants so '&' and '&amp;' both match
function sc_search_term_variants($raw) {
  $raw = (string) $raw;
  $raw = trim($raw);
  if ($raw === '') return [];

  // Decode entities (so a&amp;o -> a&o)
  $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));

  // Re-encode (so a&o -> a&amp;o)
  $encoded = htmlentities($decoded, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));

  // Also normalize special dash spaces etc if needed in future (kept minimal here)
  $variants = array_unique(array_filter([
    $raw,
    $decoded,
    $encoded,
  ]));

  return $variants;
}

// Title + Tag filtering (AND across words, OR across variants per word)
add_filter('posts_search', function ($search, $q) {
  if (is_admin() || !$q->is_search()) return $search;

  global $wpdb;

  // Important: unslash + decode entities so '&' searches work reliably
  $s = wp_unslash((string) $q->get('s'));
  $s = trim($s);
  if ($s === '') return $search;

  $tokens = array_filter(array_map('trim', preg_split('/\s+/', $s)));
  if (empty($tokens)) return $search;

  $per_token_sql = [];

  foreach ($tokens as $token) {
    $variants = sc_search_term_variants($token);
    if (empty($variants)) continue;

    $title_or = [];
    $tag_or   = [];
    $params   = [];

    foreach ($variants as $v) {
      $like = '%' . $wpdb->esc_like($v) . '%';
      $title_or[] = "{$wpdb->posts}.post_title LIKE %s";
      $tag_or[]   = "t.name LIKE %s";
      $params[]   = $like;
    }

    // Build EXISTS once, with OR across LIKEs for tag name
    $sql =
      "( (" . implode(' OR ', $title_or) . ")
         OR EXISTS (
           SELECT 1
           FROM {$wpdb->term_relationships} r
           JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = 'post_tag'
           JOIN {$wpdb->terms} t ON t.term_id = x.term_id
           WHERE r.object_id = {$wpdb->posts}.ID
             AND (" . implode(' OR ', $tag_or) . ")
         )
       )";

    $per_token_sql[] = $wpdb->prepare($sql, array_merge($params, $params));
  }

  if (empty($per_token_sql)) return $search;

  // Require all tokens to match (AND)
  $where_all = '(' . implode(' AND ', $per_token_sql) . ')';

  return " AND $where_all ";
}, 10, 2);

// Avoid duplicates from joins
add_filter('posts_groupby', function ($groupby, $q) {
  if (is_admin() || !$q->is_search()) return $groupby;
  global $wpdb;

  $id = "{$wpdb->posts}.ID";
  if (!$groupby) return $id;
  if (strpos($groupby, $id) === false) $groupby .= ", $id";
  return $groupby;
}, 10, 2);

add_filter('posts_distinct', function ($distinct, $q) {
  if (is_admin() || !$q->is_search()) return $distinct;
  return 'DISTINCT';
}, 10, 2);

// ORDER BY: prefer title starts-with, then title contains, then tag contains, then newest
add_filter('posts_orderby', function ($orderby, $q) {
  if (is_admin() || !$q->is_search() || !$q->is_main_query()) return $orderby;

  global $wpdb;

  $s = wp_unslash((string) $q->get('s'));
  $s = trim($s);
  if ($s === '') return $orderby;

  // Use decoded query for relevance ordering too
  $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
  $encoded = htmlentities($decoded, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));

  // We'll consider both decoded and encoded in relevance checks
  $starts_d = $wpdb->esc_like($decoded) . '%';
  $any_d    = '%' . $wpdb->esc_like($decoded) . '%';
  $starts_e = $wpdb->esc_like($encoded) . '%';
  $any_e    = '%' . $wpdb->esc_like($encoded) . '%';

  $case = $wpdb->prepare(
    "CASE
       WHEN {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_title LIKE %s THEN 0
       WHEN {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_title LIKE %s THEN 1
       WHEN EXISTS (
         SELECT 1 FROM {$wpdb->term_relationships} r
         JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = 'post_tag'
         JOIN {$wpdb->terms} t ON t.term_id = x.term_id
         WHERE r.object_id = {$wpdb->posts}.ID
           AND (t.name LIKE %s OR t.name LIKE %s)
       ) THEN 2
       ELSE 3
     END",
    $starts_d, $starts_e,
    $any_d, $any_e,
    $any_d, $any_e
  );

  return "$case ASC, {$wpdb->posts}.post_date DESC";
}, 9999, 2);
