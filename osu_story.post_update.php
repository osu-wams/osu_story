<?php

/**
 * @file
 * OSU Story Post Update Functions.
 */

use Drupal\Component\Serialization\Json;
use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;

/**
 * Updates meta-tags for an external story node.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node object to update. Passed by reference, as all PHP objects are.
 *    See: https://www.php.net/manual/en/language.oop5.references.php.
 * @param string $external_url
 *   The external URL to apply.
 */
function _osu_story_update_external_story_meta_tags(Node $node, string $external_url): void {
  if ($node->hasField('field_meta_tags')) {
    $meta_tag_field = $node->get('field_meta_tags')->getValue();
    if (!empty($meta_tag_field)) {
      $meta_tags = Json::decode($meta_tag_field[0]['value']);
      $meta_tags['canonical_url'] = $external_url;
      $meta_tags['robots'] = 'noindex, nofollow';
      $meta_tags['og_url'] = $external_url;
      $node->set('field_meta_tags', json_encode($meta_tags));
    }
  }
}

/**
 * Updates XML Sitemap settings for an external story node.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node object to update. Passed by reference, as all PHP objects are.
 *    See: https://www.php.net/manual/en/language.oop5.references.php.
 */
function _osu_story_exclude_story_from_sitemap(Node $node): void {
  $xmlsitemap_settings = $node->xmlsitemap;
  $xmlsitemap_settings['status'] = FALSE;
  $xmlsitemap_settings['status_override'] = 1;
  $node->xmlsitemap = $xmlsitemap_settings;
}

/**
 * Ensures a redirect exists for the story node to the external URL.
 *
 * @param \Drupal\node\Entity\Node $node
 *   The node object to update. Passed by reference, as all PHP objects are.
 *    See: https://www.php.net/manual/en/language.oop5.references.php.
 * @param string $external_url
 *   The external URL to apply.
 *
 * @throws \Drupal\Core\Entity\EntityStorageException
 * @throws \Drupal\Core\Entity\EntityMalformedException
 */
function _osu_story_ensure_story_redirect(Node $node, string $external_url): void {
  $redirect_repository = \Drupal::service('redirect.repository');
  $source_path = $node->toUrl()->getInternalPath();
  $source_redirects = $redirect_repository->findBySourcePath($source_path);
  $language = $node->get('langcode')->value;
  $urlHash = Redirect::generateHash($source_path, [], $language);
  /** @var Drupal\redirect\Entity\Redirect $source_redirect */
  foreach ($source_redirects as $source_redirect) {
    if ($source_redirect->getHash() == $urlHash) {
      return;
    }
  }

  $redirect_config = \Drupal::config('redirect.settings');
  $redirect = Redirect::create();
  $redirect->setSource($source_path);
  $redirect->setStatusCode($redirect_config->get('default_status_code'));
  $redirect->setRedirect($external_url);
  $redirect->save();
}

/**
 * Update All OSU Stories.
 *
 * If the Story has an external URL, update the meta-tags, exclude the story
 * from the XML Sitemap, and create a redirect to the external URL.
 */
function osu_story_post_update_process_external_urls(array &$sandbox): string {
  if (!isset($sandbox['total'])) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'story')
      ->condition('field_osu_story_external_url', '', '<>')
      ->accessCheck(FALSE);

    $nids = $query->execute();
    $sandbox['total'] = count($nids);
    $sandbox['current'] = 0;
    $sandbox['nids'] = $nids;
    $sandbox['processed'] = 0;

    if (empty($sandbox['total'])) {
      return t('No story nodes with external URLs found to process.');
    }
  }

  $batch_size = 10;
  $batch_nids = array_slice($sandbox['nids'], $sandbox['current'], $batch_size);

  $nodes = Node::loadMultiple($batch_nids);
  foreach ($nodes as $node) {
    /** @var \Drupal\node\Entity\Node $node */
    if ($node->get('field_osu_story_external_url')->isEmpty()) {
      $sandbox['current']++;
      continue;
    }
    $url_item = $node->get('field_osu_story_external_url')->first();
    $external_url = $url_item ? $url_item->getUrl()->toString() : '';

    // IMPORTANT:
    // PHP objects like $node are passed by reference by default.
    // Modifications to $node within these helper functions will persist in the
    // original object.
    // See: https://www.php.net/manual/en/language.oop5.references.php
    _osu_story_update_external_story_meta_tags($node, $external_url);
    _osu_story_exclude_story_from_sitemap($node);
    _osu_story_ensure_story_redirect($node, $external_url);

    $node->save();
    $sandbox['processed']++;
    $sandbox['current']++;
  }

  if ($sandbox['current'] < $sandbox['total']) {
    $sandbox['#finished'] = $sandbox['current'] / $sandbox['total'];
    return t('Processed @processed story nodes with external URLs (@current of @total).', [
      '@processed' => $sandbox['processed'],
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
