
<div class="module_blog">
    <div class="module_blog_post">
      <h2><?php echo $view->link($post->title, array('page' => $page->url, 'module_name' => 'Blog_Post', 'module_id' => $module->id, 'module_item' => $post->id), 'module_item'); ?></h2>
      <p><small><?php echo $view->toDate($post->date_published); ?></small></p>
      <p><?php echo $post->description; ?></p>
    </div>
</div>