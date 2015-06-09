<?php

/**
 * @file
 * Contains Drupal\advanced_help\Controller\AdvancedHelpController.
 */

namespace Drupal\advanced_help\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Xss;
/**
 * Class AdvancedHelpController.
 *
 * @package Drupal\advanced_help\Controller\AdvancedHelpController.
 */
class AdvancedHelpController extends ControllerBase {

  /**
   * @var THe advanced help plugin.
   */
  private $advanced_help;


  public function __construct() {
    $this->advanced_help = \Drupal::service('plugin.manager.advanced_help');
  }

  /**
   * Content.
   *
   * @todo Implement search integration.
   * @return array
   *   Returns module index.
   */
  public function main() {
    $topics = $this->advanced_help->getTopics();
    $settings = $this->advanced_help->getSettings();

    // Print a module index.
    $modules = $this->advanced_help->getModuleList();
    asort($modules);

    $items = [];
    foreach ($modules as $module => $module_name) {
      if (!empty($topics[$module]) && empty($settings[$module]['hide'])) {
        if (isset($settings[$module]['index name'])) {
          $name = $settings[$module]['index name'];
        }
        elseif (isset($settings[$module]['name'])) {
          $name = $settings[$module]['name'];
        }
        else {
          $name = $this->t($module_name);
        }
        $items[] = $this->l($name, new Url('advanced_help.module_index', ['module' => $module]));
      }
    }

    return [
      'help_modules' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#title' => $this->t('Module help index'),
      ]
    ];
  }

  /**
   * Build a hierarchy for a single module's topics.
   *
   * @param $topics array.
   * @return array.
   */
  private function getTopicHierarchy($topics) {
    foreach ($topics as $module => $module_topics) {
      foreach ($module_topics as $topic => $info) {
        $parent_module = $module;
        // We have a blank topic that we don't want parented to itself.
        if (!$topic) {
          continue;
        }

        if (empty($info['parent'])) {
          $parent = '';
        }
        elseif (strpos($info['parent'], '%')) {
          list($parent_module, $parent) = explode('%', $info['parent']);
          if (empty($topics[$parent_module][$parent])) {
            // If it doesn't exist, top level.
            $parent = '';
          }
        }
        else {
          $parent = $info['parent'];
          if (empty($module_topics[$parent])) {
            // If it doesn't exist, top level.
            $parent = '';
          }
        }

        if (!isset($topics[$parent_module][$parent]['children'])) {
          $topics[$parent_module][$parent]['children'] = [];
        }
        $topics[$parent_module][$parent]['children'][] = [$module, $topic];
        $topics[$module][$topic]['_parent'] = [$parent_module, $parent];
      }
    }
    return $topics;
  }

  /**
   * Helper function to sort topics.
   */
  private function helpUasort($id_a, $id_b) {
    $topics = $this->advanced_help->getTopics();
    list($module_a, $topic_a) = $id_a;
    $a = $topics[$module_a][$topic_a];
    list($module_b, $topic_b) = $id_b;
    $b = $topics[$module_b][$topic_b];

    $a_weight = isset($a['weight']) ? $a['weight'] : 0;
    $b_weight = isset($b['weight']) ? $b['weight'] : 0;
    if ($a_weight != $b_weight) {
      return ($a_weight < $b_weight) ? -1 : 1;
    }

    if ($a['title'] != $b['title']) {
      return ($a['title'] < $b['title']) ? -1 : 1;
    }
    return 0;
  }

  /**
   * Build a tree of advanced help topics.
   *
   * @param array $topics
   *   Topics.
   * @param array $topic_ids
   *   Topic Ids.
   * @param int $max_depth
   *   Maximum depth for subtopics.
   * @param int $depth
   *   Default depth for subtopics.
   *
   * @return array
   *   Returns list of topics/subtopics.
   */
  private function getTree($topics, $topic_ids, $max_depth = -1, $depth = 0) {
    uasort($topic_ids, [$this, 'helpUasort']);
    $items = [];
    foreach ($topic_ids as $info) {
      list($module, $topic) = $info;
      $item = $this->l($topics[$module][$topic]['title'], new Url('advanced_help.help', ['module' => $module, 'topic' => $topic]));
      if (!empty($topics[$module][$topic]['children']) && ($max_depth == -1 || $depth < $max_depth)) {
        $link = [
          '#theme' => 'item_list',
          '#items' => advanced_help_get_tree($topics, $topics[$module][$topic]['children'], $max_depth, $depth + 1),
        ];
        $item .= \Drupal::service('renderer')->render($link, FALSE);
      }
      $items[] = $item;
    }

    return $items;
  }


  public function moduleIndex($module) {
    $topics = $this->advanced_help->getTopics();

    if (empty($topics[$module])) {
      throw new NotFoundHttpException();
    }

    $topics = $this->getTopicHierarchy($topics);
    $items = $this->getTree($topics, $topics[$module]['']['children']);

    return [
      'index' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ]
    ];
  }

  public function moduleIndexTitle($module) {
    return $module;
  }

  public function topicPage($module, $topic) {
    $info = $this->advanced_help->getTopic($module, $topic);
    if (!$info) {
      throw new NotFoundHttpException();
    }

    $parent = $info;
    $pmodule = $module;

    // Loop checker.
    $checked = array();
    while (!empty($parent['parent'])) {
      if (strpos($parent['parent'], '%')) {
        list($pmodule, $ptopic) = explode('%', $parent['parent']);
      }
      else {
        $ptopic = $parent['parent'];
      }

      if (!empty($checked[$pmodule][$ptopic])) {
        break;
      }

      $checked[$pmodule][$ptopic] = TRUE;

      $parent = $this->advanced_help->getTopic($pmodule, $ptopic);
      if (!$parent) {
        break;
      }

    }

    $output = $this->viewTopic($module, $topic);
    if (empty($output)) {
      $output = $this->t('Missing help topic.');
    }

    //drupal_add_css(drupal_get_path('module', 'advanced_help') . '/help.css');
    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

    /**
   * Load and render a help topic.
   *
   * @param string $module
   *   Name of the module.
   * @param string $topic
   *   Name of the topic.
   * @todo integrate with the markdown filter module.
   * @todo Let the topics add their custom CSS file.
   * @todo port the drupal_alter functionality.
   *
   * @return string
   *   Returns formatted topic.
   */
  public function viewTopic($module, $topic) {
    $file_info = $this->advanced_help->getTopicFileInfo($module, $topic);
    if ($file_info) {
      $info = $this->advanced_help->getTopic($module, $topic);
      $file = "{$file_info['path']}/{$file_info['file']}";

      $output = file_get_contents($file);

      // @todo check the status of the markdown filter module for D8.
      if (isset($info['readme file']) && $info['readme file']) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ('md' == $ext && $this->advanced_help->isMarkdownFilterEnabled()) {
          libraries_load('php-markdown', 'markdown-extra');
          $output = '<div class="advanced-help-topic">' . Xss::filterAdmin(\Michelf\MarkdownExtra::defaultTransform($output)) . '</div>';
        }
        else {
          $readme = '';
          if ('md' == $ext) {
            $readme .=
              '<p>' .
              $this->t('If you install the !module module, the text below will be filtered by the module, producing rich text.',
                [
                  '!module' => $this->l($this->t('Markdown filter'),
                    Url::fromUri('https://www.drupal.org/project/markdown'),
                    ['attributes' => ['title' => $this->t('Link to project.')]])
                ]) . '</p>';
          }

          $readme .=
            '<div class="advanced-help-topic"><pre class="readme">' . SafeMarkup::checkPlain($output) . '</pre></div>';
          $output = $readme;
        }
        return $output;
      }


      // Change 'topic:' to the URL for another help topic.
      preg_match('/&topic:([^"]+)&/', $output, $matches);
      if (isset($matches[1]) && preg_match('/[\w\-]\/[\w\-]+/', $matches[1])) {
        list($umodule, $utopic) = explode('/', $matches[1]);
        $path = new Url('advanced_help.help', ['module' => $umodule, 'topic' => $utopic]);
        $output = preg_replace('/&topic:([^"]+)&/', $path->toString(), $output);
      }

      global $base_path;

      // Change 'path:' to the URL to the base help directory.
      $output = str_replace('&path&', $base_path . $info['path'] . '/', $output);

      // Change 'trans_path:' to the URL to the actual help directory.
      $output = str_replace('&trans_path&', $base_path . $file_info['path'] . '/', $output);

      // Change 'base_url:' to the URL to the site.
      $output = preg_replace('/&base_url&([^"]+)"/', $base_path . '$1' . '"', $output);

      // Run the line break filter if requested.
      if (!empty($info['line break'])) {
        // Remove the header since it adds an extra <br /> to the filter.
        $output = preg_replace('/^<!--[^\n]*-->\n/', '', $output);

        $output = _filter_autop($output);
      }

      if (!empty($info['navigation'])) {
        $topics = $this->advanced_help->getTopics();
        $topics = $this->getTopicHierarchy($topics);
        if (!empty($topics[$module][$topic]['children'])) {
          $items = $this->getTree($topics, $topics[$module][$topic]['children']);
          $links = [
            '#theme' => 'item_list',
            '#items' => $items
          ];
          $output .= \Drupal::service('renderer')->render($links, FALSE);
        }

        list($parent_module, $parent_topic) = $topics[$module][$topic]['_parent'];
        if ($parent_topic) {
          $parent = $topics[$module][$topic]['_parent'];
          $up = new Url('advanced_help.help', ['module' => $parent[0], 'topic' => $parent[1]]);
        }
        else {
          $up = new Url('advanced_help.module_index', ['module' => $module]);
        }

        $siblings = $topics[$parent_module][$parent_topic]['children'];
        uasort($siblings, [$this, 'helpUasort']);
        $prev = $next = NULL;
        $found = FALSE;
        foreach ($siblings as $sibling) {
          list($sibling_module, $sibling_topic) = $sibling;
          if ($found) {
            $next = $sibling;
            break;
          }
          if ($sibling_module == $module && $sibling_topic == $topic) {
            $found = TRUE;
            continue;
          }
          $prev = $sibling;
        }

        if ($prev || $up || $next) {
          $navigation = '<div class="help-navigation clear-block">';

          if ($prev) {
            $navigation .= $this->l('«« ' . $topics[$prev[0]][$prev[1]]['title'], new Url('advanced_help.help', ['module' => $prev[0], 'topic' => $prev[1]], ['attributes' => ['class' => 'help-left']]));
          }
          if ($up) {
            $navigation .= $this->l($this->t('Up'), $up->setOption('attributes', ['class' => ($prev) ? 'help-up' : 'help-up-noleft']));
          }
          if ($next) {
            $navigation .= $this->l($topics[$next[0]][$next[1]]['title'] . ' »»', new Url('advanced_help.help', ['module' => $next[0], 'topic' => $next[1]], ['attributes' => ['class' => 'help-right']]));
          }

          $navigation .= '</div>';

          $output .= $navigation;
        }
      }

//      if (!empty($info['css'])) {
//        drupal_add_css($info['path'] . '/' . $info['css']);
//      }

      $output = '<div class="advanced-help-topic">' . $output . '</div>';
//      drupal_alter('advanced_help_topic', $output, $popup);

      return $output;
    }
  }


  public function topicPageTitle($module, $topic) {
    return $topic;
  }
}