<?php

use Drupal\node\Entity\Node;

/**
 * Implements hook_post_update_NAME().
 *
 * Process all story nodes with external URLs to apply pre_save logic.
 *
 * This is a Drupal 11 compatible post update hook that uses Batch API to
 * process all story nodes with external URLs and applies the same logic as in
 * the osu_story_node_presave() hook to ensure consistent handling of external
 * URLs.
 *
 * @param array $sandbox
 *   Sandbox array for batch operations.
 *
 * @return string
 *   A message to display when the update is complete.
 */
function osu_story_post_update_process_external_urls(&$sandbox) {
  // Initialize the batch on the first run.
  if (!isset($sandbox['total'])) {
    // Get the total count of story nodes with external URLs.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'story')
      ->condition('field_osu_story_external_url', '', '<>')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    $sandbox['total'] = count($nids);
    $sandbox['current'] = 0;
    $sandbox['nids'] = $nids;

    // If there are no nodes to process, return early.
    if (empty($sandbox['total'])) {
      return t('No story nodes with external URLs found to process.');
    }
  }

  // Process nodes in batches of 10.
  $batch_size = 10;
  $end = min($sandbox['current'] + $batch_size, $sandbox['total']);
  $batch_nids = array_slice($sandbox['nids'], $sandbox['current'], $batch_size);

  // Load and process the nodes.
  $nodes = Node::loadMultiple($batch_nids);
  $processed = 0;

  foreach ($nodes as $node) {
    if ($node->bundle() == 'story' && !$node->get('field_osu_story_external_url')
        ->isEmpty()) {
      // Apply the same logic as in osu_story_node_presave().
      $url_item = $node->get('field_osu_story_external_url')->first();
      $external_url = $url_item ? $url_item->getUrl()->toString() : '';

      // Meta tag updates.
      if ($node->hasField('field_meta_tags')) {
        $meta_tag_field = $node->get('field_meta_tags')->getValue();
        if (!empty($meta_tag_field)) {
          // Decode the JSON string.
          $meta_tags = \Drupal\Component\Serialization\Json::decode($meta_tag_field[0]['value']);
          // Update meta-tags for external story.
          $meta_tags['canonical_url'] = $external_url;
          $meta_tags['robots'] = 'noindex, nofollow';
          $meta_tags['og_url'] = $external_url;
          // Set the field value.
          $node->set('field_meta_tags', json_encode($meta_tags));
        }
      }

      // XML Sitemap, exclude this page.
      $xmlsitemap_settings = $node->xmlsitemap;
      $xmlsitemap_settings['status'] = FALSE;
      $xmlsitemap_settings['status_override'] = 1;
      $node->xmlsitemap = $xmlsitemap_settings;

      // Create a redirect if one does not already exist for this nid and external url.
      /** @var \Drupal\redirect\RedirectRepository $redirect_repository */
      $redirect_repository = \Drupal::service('redirect.repository');
      $source_path = $node->toUrl()->getInternalPath();
      $source_redirects = $redirect_repository->findBySourcePath($source_path);
      $redirect_found = FALSE;

      // Find the redirect if it already exists.
      foreach ($source_redirects as $source_redirect) {
        if ($source_redirect->getRedirectUrl()
            ->toUriString() == $external_url) {
          $redirect_found = TRUE;
          break;
        }
      }

      // We should only have one Redirect created for a given nid and external url.
      if (!$redirect_found) {
        $redirect_config = \Drupal::config('redirect.settings');
        $redirect = \Drupal\redirect\Entity\Redirect::create();
        $redirect->setSource($node->toUrl()->getInternalPath());
        $redirect->setStatusCode($redirect_config->get('default_status_code'));
        $redirect->setRedirect($external_url);
        $redirect->save();
      }

      // Save the node to apply changes.
      $node->save();
      $processed++;
    }

    $sandbox['current']++;
  }

  // Update progress.
  if ($sandbox['current'] < $sandbox['total']) {
    $sandbox['#finished'] = $sandbox['current'] / $sandbox['total'];
    return t('Processed @processed story nodes with external URLs (@current of @total)', [
      '@processed' => $processed,
      '@current' => $sandbox['current'],
      '@total' => $sandbox['total'],
    ]);
  }
  else {
    $sandbox['#finished'] = 1;
    return t('Processed a total of @total story nodes with external URLs.', [
      '@total' => $sandbox['total'],
    ]);
  }
}
