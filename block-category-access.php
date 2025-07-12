<?php
/*
Plugin Name: Block Taxonomy Access
Description: 屏蔽前台访问指定分类、标签页面及相关文章，支持后台单独设置与开关，兼容B2主题前台搜索与robots控制。
Version: 1.6
Author: 码铃薯
License: GPLv2 or later
Text Domain: block-taxonomy-access
*/

if (!defined('ABSPATH')) exit;

// 注册后台设置页面
add_action('admin_menu', function () {
    add_options_page(
        '分类与标签访问控制',
        '分类标签访问控制',
        'manage_options',
        'block-taxonomy-access',
        'bta_settings_page'
    );
});

// 注册设置项
add_action('admin_init', function () {
    register_setting('bta_settings_group', 'bta_blocked_categories');
    register_setting('bta_settings_group', 'bta_blocked_tags');
    register_setting('bta_settings_group', 'bta_block_posts_enabled');
    register_setting('bta_settings_group', 'bta_hide_blocked_categories_in_menu');
    register_setting('bta_settings_group', 'bta_robots_noindex_enabled');
});

// 后台设置页面
function bta_settings_page() {
    $cat_slugs = esc_attr(get_option('bta_blocked_categories', ''));
    $tag_slugs = esc_attr(get_option('bta_blocked_tags', ''));
    $block_posts = get_option('bta_block_posts_enabled', '0');
    $hide_menu = get_option('bta_hide_blocked_categories_in_menu', '0');
    $robots_noindex = get_option('bta_robots_noindex_enabled', '0');
    ?>
    <div class="wrap">
        <h1>分类与标签访问控制</h1>
        <form method="post" action="options.php">
            <?php settings_fields('bta_settings_group'); do_settings_sections('bta_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th>屏蔽的分类别名（slug）</th>
                    <td><input type="text" name="bta_blocked_categories" value="<?php echo $cat_slugs; ?>" class="regular-text">
                    <p class="description">多个 slug 用英文逗号分隔，例如：switch,nsp,xci</p></td>
                </tr>
                <tr>
                    <th>屏蔽的标签别名（slug）</th>
                    <td><input type="text" name="bta_blocked_tags" value="<?php echo $tag_slugs; ?>" class="regular-text">
                    <p class="description">多个 slug 用英文逗号分隔，例如：破解,rom,任天堂</p></td>
                </tr>
                <tr>
                    <th>屏蔽相关文章访问</th>
                    <td><label><input type="checkbox" name="bta_block_posts_enabled" value="1" <?php checked($block_posts, '1'); ?>> 启用后，被屏蔽分类/标签下的文章访问返回404</label></td>
                </tr>
                <tr>
                    <th>隐藏菜单中的屏蔽分类</th>
                    <td><label><input type="checkbox" name="bta_hide_blocked_categories_in_menu" value="1" <?php checked($hide_menu, '1'); ?>> 启用后，菜单中将移除屏蔽分类</label></td>
                </tr>
                <tr>
                    <th>Robots noindex 屏蔽</th>
                    <td><label><input type="checkbox" name="bta_robots_noindex_enabled" value="1" <?php checked($robots_noindex, '1'); ?>> 启用后，被屏蔽页面添加 noindex 标签</label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// 页面访问拦截（分类/标签/文章）
add_action('template_redirect', function () {
    $blocked_categories = array_map('trim', explode(',', get_option('bta_blocked_categories', '')));
    $blocked_tags = array_map('trim', explode(',', get_option('bta_blocked_tags', '')));
    $block_posts = get_option('bta_block_posts_enabled', '0');

    // 分类页面
    if (is_category()) {
        $obj = get_queried_object();
        if ($obj && in_array($obj->slug, $blocked_categories)) bta_block_access();
    }

    // 标签页面
    if (is_tag()) {
        $obj = get_queried_object();
        if ($obj && in_array($obj->slug, $blocked_tags)) bta_block_access();
    }

    // 文章页面
    if (is_single() && $block_posts === '1') {
        global $post;
        if (!$post) return;

        // 分类检查
        $cats = get_the_category($post->ID);
        foreach ($cats as $cat) {
            if (in_array($cat->slug, $blocked_categories)) bta_block_access();
        }

        // 标签检查
        $tags = get_the_tags($post->ID);
        if ($tags) {
            foreach ($tags as $tag) {
                if (in_array($tag->slug, $blocked_tags)) bta_block_access();
            }
        }
    }
});

// 设置404
function bta_block_access() {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    include get_query_template('404');
    exit;
}

// 搜索拦截（前台 + 后台）
add_action('pre_get_posts', function ($query) {
    if (!$query->is_main_query()) return;

    $blocked_categories = array_map('trim', explode(',', get_option('bta_blocked_categories', '')));
    $blocked_tags = array_map('trim', explode(',', get_option('bta_blocked_tags', '')));

    $cat_ids = array_filter(array_map(function ($slug) {
        $cat = get_category_by_slug($slug); return $cat ? $cat->term_id : null;
    }, $blocked_categories));

    $tag_ids = array_filter(array_map(function ($slug) {
        $tag = get_term_by('slug', $slug, 'post_tag'); return $tag ? $tag->term_id : null;
    }, $blocked_tags));

    if ($query->is_search() || (defined('DOING_AJAX') && DOING_AJAX)) {
        if (!empty($cat_ids)) $query->set('category__not_in', $cat_ids);
        if (!empty($tag_ids)) $query->set('tag__not_in', $tag_ids);
    }
});

// 菜单隐藏
add_filter('wp_get_nav_menu_items', function ($items, $menu, $args) {
    if (get_option('bta_hide_blocked_categories_in_menu') !== '1') return $items;

    $blocked_slugs = array_map('trim', explode(',', get_option('bta_blocked_categories', '')));
    $blocked_ids = array_filter(array_map(function ($slug) {
        $cat = get_category_by_slug($slug); return $cat ? $cat->term_id : null;
    }, $blocked_slugs));

    foreach ($items as $key => $item) {
        if ($item->object === 'category' && in_array(intval($item->object_id), $blocked_ids)) {
            unset($items[$key]);
        }
    }
    return $items;
}, 10, 3);

// robots noindex 注入
add_action('wp_head', function () {
    if (get_option('bta_robots_noindex_enabled') !== '1') return;

    $blocked_categories = array_map('trim', explode(',', get_option('bta_blocked_categories', '')));
    $blocked_tags = array_map('trim', explode(',', get_option('bta_blocked_tags', '')));

    $should_noindex = false;

    if (is_category()) {
        $obj = get_queried_object();
        $should_noindex = $obj && in_array($obj->slug, $blocked_categories);
    } elseif (is_tag()) {
        $obj = get_queried_object();
        $should_noindex = $obj && in_array($obj->slug, $blocked_tags);
    } elseif (is_single()) {
        global $post;
        $cats = get_the_category($post->ID);
        foreach ($cats as $cat) {
            if (in_array($cat->slug, $blocked_categories)) $should_noindex = true;
        }
        $tags = get_the_tags($post->ID);
        if ($tags) {
            foreach ($tags as $tag) {
                if (in_array($tag->slug, $blocked_tags)) $should_noindex = true;
            }
        }
    }

    if ($should_noindex) {
        echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
    }
});
