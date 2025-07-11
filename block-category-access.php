<?php
/*
Plugin Name: block category access
Description: 屏蔽前台访问指定分类、标签页面及相关文章，支持后台单独设置与开关（规避版权审查风险性规避行为！）
Version: 1.5
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
    register_setting('bta_settings_group', 'bta_robots_noindex_enabled'); // robots noindex开关
});

// 后台设置页面 HTML
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
            <?php
            settings_fields('bta_settings_group');
            do_settings_sections('bta_settings_group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">屏蔽的分类别名（slug）</th>
                    <td>
                        <input type="text" name="bta_blocked_categories" value="<?php echo $cat_slugs; ?>" class="regular-text" />
                        <p class="description">多个 slug 请用英文逗号分隔，例如：switch,nsp,xci</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">屏蔽的标签别名（slug）</th>
                    <td>
                        <input type="text" name="bta_blocked_tags" value="<?php echo $tag_slugs; ?>" class="regular-text" />
                        <p class="description">多个 slug 请用英文逗号分隔，例如：rom,破解,任天堂</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">屏蔽相关文章访问</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bta_block_posts_enabled" value="1" <?php checked($block_posts, '1'); ?> />
                            启用后，如果文章属于上述分类或标签，将直接返回 404
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">隐藏菜单中屏蔽分类</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bta_hide_blocked_categories_in_menu" value="1" <?php checked($hide_menu, '1'); ?> />
                            启用后，菜单中屏蔽分类将不会显示
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Robots noindex 屏蔽</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bta_robots_noindex_enabled" value="1" <?php checked($robots_noindex, '1'); ?> />
                            启用后，前端屏蔽分类、标签、文章页面将自动添加 noindex，阻止搜索引擎收录
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// 前端拦截逻辑
add_action('template_redirect', function () {
    $blocked_categories = array_filter(array_map('trim', explode(',', get_option('bta_blocked_categories', ''))));
    $blocked_tags = array_filter(array_map('trim', explode(',', get_option('bta_blocked_tags', ''))));
    $block_posts = get_option('bta_block_posts_enabled', '0');

    // 分类页面拦截
    if (is_category()) {
        $obj = get_queried_object();
        if ($obj && in_array($obj->slug, $blocked_categories)) {
            bta_block_access();
        }
    }

    // 标签页面拦截
    if (is_tag()) {
        $obj = get_queried_object();
        if ($obj && in_array($obj->slug, $blocked_tags)) {
            bta_block_access();
        }
    }

    // 文章页面拦截
    if (is_single() && $block_posts === '1') {
        global $post;

        // 分类检查
        $cats = get_the_category($post->ID);
        foreach ($cats as $cat) {
            if (in_array($cat->slug, $blocked_categories)) {
                bta_block_access();
            }
        }

        // 标签检查
        $tags = get_the_tags($post->ID);
        if ($tags) {
            foreach ($tags as $tag) {
                if (in_array($tag->slug, $blocked_tags)) {
                    bta_block_access();
                }
            }
        }
    }
});

// 统一封装阻断函数
function bta_block_access() {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    include get_query_template('404');
    exit;
}

// 搜索结果过滤，屏蔽指定分类和标签文章
add_action('pre_get_posts', function ($query) {
    if ($query->is_search() && $query->is_main_query()) {
        $blocked_categories = array_filter(array_map('trim', explode(',', get_option('bta_blocked_categories', ''))));
        $blocked_tags = array_filter(array_map('trim', explode(',', get_option('bta_blocked_tags', ''))));

        $cat_ids = [];
        foreach ($blocked_categories as $slug) {
            $cat = get_category_by_slug($slug);
            if ($cat) $cat_ids[] = $cat->term_id;
        }

        $tag_ids = [];
        foreach ($blocked_tags as $slug) {
            $tag = get_term_by('slug', $slug, 'post_tag');
            if ($tag) $tag_ids[] = $tag->term_id;
        }

        if ($cat_ids) {
            $query->set('category__not_in', $cat_ids);
        }

        if ($tag_ids) {
            $query->set('tag__not_in', $tag_ids);
        }
    }
});

// 过滤分类列表，兼容B2主题等，屏蔽指定分类
add_filter('get_terms', function ($terms, $taxonomies, $args) {
    if (empty($terms) || !in_array('category', (array)$taxonomies)) {
        return $terms;
    }

    $blocked_slugs = get_option('bta_blocked_categories', '');
    if (!$blocked_slugs) return $terms;

    $blocked_slugs = array_map('trim', explode(',', $blocked_slugs));
    if (empty($blocked_slugs)) return $terms;

    $blocked_ids = [];
    foreach ($blocked_slugs as $slug) {
        $cat = get_category_by_slug($slug);
        if ($cat) $blocked_ids[] = $cat->term_id;
    }
    if (empty($blocked_ids)) return $terms;

    foreach ($terms as $key => $term) {
        if (in_array($term->term_id, $blocked_ids)) {
            unset($terms[$key]);
        }
    }

    return $terms;
}, 10, 3);

// 根据后台开关，动态过滤菜单中的屏蔽分类菜单项
add_action('init', function () {
    $hide_menu = get_option('bta_hide_blocked_categories_in_menu', '0');
    if ($hide_menu === '1') {
        add_filter('wp_get_nav_menu_items', function ($items, $menu, $args) {
            $blocked_slugs = get_option('bta_blocked_categories', '');
            if (!$blocked_slugs) return $items;

            $blocked_slugs = array_map('trim', explode(',', $blocked_slugs));
            if (empty($blocked_slugs)) return $items;

            $blocked_ids = [];
            foreach ($blocked_slugs as $slug) {
                $cat = get_category_by_slug($slug);
                if ($cat) $blocked_ids[] = $cat->term_id;
            }
            if (empty($blocked_ids)) return $items;

            foreach ($items as $key => $item) {
                if ($item->object === 'category' && in_array(intval($item->object_id), $blocked_ids)) {
                    unset($items[$key]);
                }
            }

            return $items;
        }, 10, 3);
    }
});

// robots noindex 头部标签注入
add_action('wp_head', function () {
    $robots_noindex = get_option('bta_robots_noindex_enabled', '0');
    if ($robots_noindex !== '1') return;

    if (is_category()) {
        $obj = get_queried_object();
        $blocked_categories = array_filter(array_map('trim', explode(',', get_option('bta_blocked_categories', ''))));
        if ($obj && in_array($obj->slug, $blocked_categories)) {
            echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        }
    }

    if (is_tag()) {
        $obj = get_queried_object();
        $blocked_tags = array_filter(array_map('trim', explode(',', get_option('bta_blocked_tags', ''))));
        if ($obj && in_array($obj->slug, $blocked_tags)) {
            echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        }
    }

    if (is_single()) {
        global $post;
        $blocked_categories = array_filter(array_map('trim', explode(',', get_option('bta_blocked_categories', ''))));
        $blocked_tags = array_filter(array_map('trim', explode(',', get_option('bta_blocked_tags', ''))));

        $cats = get_the_category($post->ID);
        foreach ($cats as $cat) {
            if (in_array($cat->slug, $blocked_categories)) {
                echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
                return;
            }
        }

        $tags = get_the_tags($post->ID);
        if ($tags) {
            foreach ($tags as $tag) {
                if (in_array($tag->slug, $blocked_tags)) {
                    echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
                    return;
                }
            }
        }
    }
});
