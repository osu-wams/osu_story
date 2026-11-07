<?php

declare(strict_types=1);

namespace Drupal\osu_story\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\redirect\RedirectRepository;

/**
 * Hook implementations for OSU Story nodes.
 */
class OsuStoryNodeHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
    private readonly RedirectRepository $redirectRepository,
  ) {}

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if ($node->bundle() === 'story'
      && $node->hasField('field_meta_tags')
      && !$node->get('field_osu_story_external_url')->isEmpty()) {
      $url_item = $node->get('field_osu_story_external_url')->first();
      $external_url = $url_item ? $url_item->getUrl()->toString() : '';

      if ($external_url === '') {
        return;
      }
      $this->externalStoryRedirect($node, $external_url);
    }
  }

  /**
   * Implements hook_node_presave().
   */
  #[Hook('node_presave')]
  public function nodePreSave(NodeInterface $node): void {
    if ($node->bundle() === 'story'
      && $node->hasField('field_meta_tags')
      && !$node->get('field_osu_story_external_url')->isEmpty()) {
      $url_item = $node->get('field_osu_story_external_url')->first();
      $external_url = $url_item ? $url_item->getUrl()->toString() : '';

      if ($external_url === '') {
        return;
      }
      $this->updateStoryMeta($node, $external_url);
    }
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    if ($node->bundle() === 'story'
      && !$node->get('field_osu_story_external_url')->isEmpty()) {
      $url_item = $node->get('field_osu_story_external_url')->first();
      $external_url = $url_item ? $url_item->getUrl()->toString() : '';

      if ($external_url === '') {
        return;
      }
      $this->externalStoryRedirect($node, $external_url);
    }
  }

  /**
   * Updates the redirects for external URLs.
   */
  private function externalStoryRedirect(NodeInterface $node, string $externalUrl): void {
    // Create a redirect if one does not already exist for this nid and
    // external url.
    $source_path = $node->toUrl()->getInternalPath();
    $source_redirects = $this->redirectRepository->findBySourcePath($source_path);
    $redirect_found = FALSE;
    // Find the redirect if it already exists.
    $language = $node->get('langcode')->value;
    $urlHash = Redirect::generateHash($source_path, [], $language);

    foreach ($source_redirects as $source_redirect) {
      if ($source_redirect->getHash() === $urlHash) {
        $redirect_found = TRUE;

        break;
      }
    }

    // We should only have one Redirect created for a given nid and
    // external url.
    if (!$redirect_found) {
      $redirect_config = $this->configFactory->get('redirect.settings');
      /** @var \Drupal\redirect\Entity\Redirect $redirect */
      $redirect = $this->entityTypeManager->getStorage('redirect')
        ->create();
      $redirect->setSource($node->toUrl()->getInternalPath());
      $redirect->setStatusCode($redirect_config->get('default_status_code'));
      $redirect->setRedirect($externalUrl);
      $redirect->save();
      // Set a status message.
      $this->messenger
        ->addStatus($this->t('<p>This story has been marked as external.<br />Meta-tags have been updated, the story is excluded from the XML Sitemap, and a redirect to the external URL has been created to protect SEO.</p>'));
    }
    else {
      // Set a status message.
      $this->messenger
        ->addStatus($this->t('<p>This story has been marked as external.<br />Meta-tags have been updated, the story is excluded from the XML Sitemap.</p>'));
    }
  }

  /**
   * Update the Story Metatags and XML Sitemap Settings.
   */
  private function updateStoryMeta(NodeInterface $node, string $externalUrl): void {
    // Meta tag updates.
    if ($node->hasField('field_meta_tags')) {
      $meta_tag_field = $node->get('field_meta_tags')->value;

      // If the field is not empty, we will update only our fields.
      if (!empty($meta_tag_field)) {
        // Decode the JSON string.
        $meta_tags = unserialize($meta_tag_field, ['allowed_classes' => FALSE]);
      }
      // Nothing was manually set in metatag this will allow us to overwrite it.
      else {
        $meta_tags = [];
      }
      // Set Meatag data.
      $meta_tags['canonical_url'] = $externalUrl;
      $meta_tags['robots'] = 'noindex, nofollow';
      $meta_tags['og_url'] = $externalUrl;
      $node->set('field_meta_tags', serialize($meta_tags));
    }
    // XML Sitemap, exclude this page.
    $xmlsitemap_settings = $node->xmlsitemap;
    $xmlsitemap_settings['status'] = FALSE;
    $xmlsitemap_settings['status_override'] = 1;
    $node->xmlsitemap = $xmlsitemap_settings;
  }

}
