<?php

/**
 * @file
 * Views integration
 */

/**
 * Implements hook_views_data_alter().
 */
function cps_views_data_alter(&$data) {
  // This uses views_data_alter() because most of the Views integration
  // is handled by entity module, but this filter is a bit special.
  $data['cps_changeset']['status_type'] = array(
    'filter' => array(
      'title' => t('Status type'),
      'help' => t('Filter by all statuses of a given type'),
      'real field' => 'status',
      'handler' => 'cps_handler_filter_status_type',
    )
  );
}
