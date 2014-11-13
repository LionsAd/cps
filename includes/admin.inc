<?php
/**
 * @file
 * Changeset administration pages.
 */

/**
 * List all entities for the given type.
 *
 * @return string
 *   A rendered page.
 */
function cps_changeset_list_page($view_name) {
  $view = views_get_view($view_name);
  if (!$view || !$view->access('default')) {
    return MENU_NOT_FOUND;
  }

  drupal_set_title(t('Site versions'));

  $view->override_path = $_GET['q'];
  return $view->preview('default');
}

/**
 * Page callback to add a new entity.
 *
 * @return array
 *   A drupal renderable array.
 */
function cps_changeset_add_entity_page() {
  $entity = cps_changeset_create();

  drupal_set_title(t('Create new site version'));

  $form_state = array(
    'entity' => $entity,
    'no_redirect' => TRUE,
    'op' => 'add',
    'args' => array(),
  ) + form_state_defaults();

  form_load_include($form_state, 'inc', 'cps', 'includes/forms');
  $output = drupal_build_form('cps_changeset_edit_form', $form_state);

  if (!empty($form_state['executed'])) {
    // Use the one on $form_state because form caching can change the object.
    $form_state['entity']->save();
    watchdog($entity->entityType(), 'Changeset: created %title.', array('%title' => $entity->label()));
    drupal_set_message(t('The site version "%title" has been created.', array('%title' => $entity->label())));
    call_user_func_array('drupal_goto', $entity->uri());
  }

  return $output;
}

/**
 * Page callback to edit a entity.
 *
 * @param CPSChangeset $entity
 *   The entity to edit.
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_edit_page(CPSChangeset $entity) {
  $form_state = array(
    'entity' => $entity,
    'no_redirect' => TRUE,
    'op' => 'edit',
  ) + form_state_defaults();

  form_load_include($form_state, 'inc', 'cps', 'includes/forms');
  $output = drupal_build_form('cps_changeset_edit_form', $form_state);

  if (!empty($form_state['executed'])) {
    // Use the one on $form_state because form caching can actually change the object.
    /** @var CPSChangeset $entity */
    $entity = $form_state['entity'];
    $entity->save();
    watchdog($entity->entityType(), 'Changeset: updated %title.', array('%title' => $entity->label()));
    drupal_set_message(t('The site version "%title" has been updated.', array('%title' => $entity->label())));
    call_user_func_array('drupal_goto', $entity->uri());
  }

  return $output;
}

/**
 * Page callback to view a entity.
 *
 * @param CPSChangeset $entity
 *   The entity to view.
 * @param string $view_mode
 *   (optional) The view mode to render. Defaults to 'full'.
 *
 * @return string
 *   A rendered page.
 */
function cps_changeset_view_page(CPSChangeset $entity, $view_mode = 'full') {
  $build = $entity->view($view_mode, NULL, TRUE);
  return drupal_render($build);
}

/**
 * Menu callback via drupal_get_form() -- ask for confirmation of entity deletion
 */
function cps_changeset_delete_form($form, &$form_state, CPSChangeset $entity) {
  $form_state['entity'] = $entity;
  $uri = $entity->uri();
  return confirm_form($form,
    t('Are you sure you want to delete %title?', array('%title' => $entity->label())),
    $uri['path'],
    t('This action cannot be undone.'),
    t('Delete'),
    t('Cancel')
  );
}

/**
 * Form submit handler to execute entity deletion
 */
function cps_changeset_delete_form_submit($form, &$form_state) {
  if ($form_state['values']['confirm']) {
    /** @var CPSChangeset $entity */
    $entity = $form_state['entity'];
    $entity->delete();
    watchdog($entity->entityType(), 'Changeset: deleted %title.', array('%title' => $entity->label()));
    drupal_set_message(t('Site version "%title" has been deleted.', array('%title' => $entity->label())));
  }

  $form_state['redirect'] = 'admin/structure/changesets';
}

/**
 * Page callback to display the publish changeset form.
 *
 * @param CPSChangeset $entity
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_publish_page(CPSChangeset $entity) {
  $form_state = array(
    'entity' => $entity,
  ) + form_state_defaults();

  form_load_include($form_state, 'inc', 'cps', 'includes/forms');
  $output = drupal_build_form('cps_changeset_publish_changeset_form', $form_state);
  return $output;
}

/**
 * Page callback to display the unpublish changeset form.
 *
 * @param CPSChangeset $entity
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_unpublish_page(CPSChangeset $entity) {
  $form_state = array(
    'entity' => $entity,
  ) + form_state_defaults();

  form_load_include($form_state, 'inc', 'cps', 'includes/forms');
  $output = drupal_build_form('cps_changeset_unpublish_changeset_form', $form_state);
  return $output;
}

/**
 * Page callback to display the publish changeset form.
 *
 * @param CPSChangeset $entity
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_view_site_page(CPSChangeset $entity) {
  $path = $entity->uri();
  drupal_goto($path['path'], array('query' => array('changeset_id' => $entity->changeset_id)));
}

/**
 * Page callback to remove an entity from the given changeset.
 *
 * @param CPSChangeset $changeset
 *   The changeset
 * @param string $entity_type
 *   The type of entity being removed.
 * @param $entity_id
 *   The id of the entity.
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_remove_page(CPSChangeset $changeset, $entity_type, $entity_id) {
  cps_override_changeset($changeset->changeset_id);
  $entities = entity_load($entity_type, array($entity_id));
  cps_override_changeset(NULL);
  if (!$entities) {
    return MENU_NOT_FOUND;
  }

  $entity = reset($entities);
  $form_state = array(
    'entity_type' => $entity_type,
    'entity' => $entity,
    'changeset' => $changeset,
  ) + form_state_defaults();

  $output = drupal_build_form('cps_changeset_remove_form', $form_state);
  return $output;
}

/**
 * Form callback to remove an entity from a changeset.
 */
function cps_changeset_remove_form($form, &$form_state) {
  /** @var CPSChangeset $changeset */
  $changeset = $form_state['changeset'];
  $entity_type = $form_state['entity_type'];
  $entity = $form_state['entity'];
  $uri = $changeset->uri();
  return confirm_form($form,
    t('Are you sure you want to remove %entity from site version %title?', array('%entity' => entity_label($entity_type, $entity), '%title' => $changeset->label())),
    $uri['path'],
    t('All changes to %entity in %title will be discarded. This action cannot be undone!', array('%entity' => entity_label($entity_type, $entity), '%title' => $changeset->label())),
    t('Remove'),
    t('Cancel')
  );
}

/**
 * Submit callback to remove an entity from a changeset.
 */
function cps_changeset_remove_form_submit($form, &$form_state) {
  if ($form_state['values']['confirm']) {
    /** @var CPSChangeset $changeset */
    $changeset = $form_state['changeset'];
    $entity_type = $form_state['entity_type'];
    $entity = $form_state['entity'];
    $changeset->removeEntity($entity_type, $entity);

    drupal_set_message(t('Changes to %entity have been removed from the site version %title.', array('%entity' => entity_label($entity_type, $entity), '%title' => $changeset->label())));
    $form_state['redirect'] = $changeset->uri();
  }
}

/**
 * Page callback to move an entity from the given changeset to the current changeset.
 *
 * @param CPSChangeset $changeset
 *   The changeset
 * @param string $entity_type
 *   The type of entity being removed.
 * @param $entity_id
 *   The id of the entity.
 *
 * @return array
 *   A Drupal renderable array.
 */
function cps_changeset_move_page(CPSChangeset $changeset, $entity_type, $entity_id) {
  $changeset_to = cps_get_current_changeset();

  cps_override_changeset($changeset->changeset_id);
  $entities = entity_load($entity_type, array($entity_id));
  cps_override_changeset(NULL);
  if (!$entities) {
    return MENU_NOT_FOUND;
  }

  $entity = reset($entities);

  // If the entity can't be updated in the current changeset...
  if (!cps_can_edit_entities('move to', $entity_type, $entity)) {
    return MENU_ACCESS_DENIED;
  }

  $form_state = array(
      'entity_type' => $entity_type,
      'entity' => $entity,
      'changeset_from' => $changeset,
      'changeset_to' => $changeset_to,
    ) + form_state_defaults();

  $output = drupal_build_form('cps_changeset_move_form', $form_state);
  return $output;
}

/**
 * Form callback to move an entity to the current changeset
 */
function cps_changeset_move_form($form, &$form_state) {
  /** @var CPSChangeset $changeset */
  $changeset_from = $form_state['changeset_from'];
  $entity_type = $form_state['entity_type'];
  $entity = $form_state['entity'];
  $uri = $changeset_from->uri();

  // We use individual radio buttons rather than #type => 'radios'
  // here so that we can disable 'move' if it isn't available.
  $form['type'] = array(
    '#type' => 'item',
    '#title' => t('Would you like to move or copy changes?'),
    '#description' => t('If you copy, the original site version will be untouched. If you move, all changes for this entity will be moved to the current site version. Note: You cannot move changes if the originating site version is not editable by you or has already been published.'),
  );

  $form['type']['move'] = array(
    '#name' => 'type',
    '#type' => 'radio',
    '#title' => t('Move'),
    '#return_value' => 'move',
    '#default_value' => FALSE,
  );

  $form['type']['copy'] = array(
    '#name' => 'type',
    '#type' => 'radio',
    '#title' => t('Copy'),
    '#return_value' => 'copy',
    '#default_value' => FALSE,
  );

  // Disable 'move' if they can't edit the original.
  if (!cps_can_edit_entities('update', $entity_type, $entity, $changeset_from->changeset_id)) {
    $form['type']['move']['#disabled'] = TRUE;
    $form['type']['copy']['#default_value'] = 'copy';
  }
  else {
    $form['type']['move']['#default_value'] = 'move';
  }

  return confirm_form($form,
    t('Move or copy changes between site versions'),
    $uri['path'],
    t('Are you sure you want to perform the selected action on "%entity"?', array('%entity' => entity_label($entity_type, $entity), '%title' => $changeset_from->label())),
    t('Confirm'),
    t('Cancel')
  );
}

/**
 * Validate callback for cps_changeset_move_form
 */
function cps_changeset_move_form_validate($form, &$form_state) {
  // Becuase we are manually constructing radios, we have to massage the results back to
  // what we expect.
  $form_state['values']['type'] = $form_state['values']['move'] ? 'move' : 'copy';
}

/**
 * Submit callback to remove an entity from a changeset.
 */
function cps_changeset_move_form_submit($form, &$form_state) {
  if ($form_state['values']['confirm']) {
    $changeset_from = $form_state['changeset_from'];
    $changeset_to = $form_state['changeset_to'];
    $entity_type = $form_state['entity_type'];
    $entity = $form_state['entity'];

    // Ensure any operations performance in hook_cps_move_changeset() are
    // performed with the correct changeset, and before the entity has been
    // updated.
    // @todo: should this pass to/from rather than relying on current changeset.
    cps_override_changeset($changeset_from->changeset_id);
    module_invoke_all('cps_move_changeset', $changeset_from, $entity_type, $entity, $form_state['values']['type']);
    list(, $revision_id) = entity_extract_ids($entity_type, $entity);
    if ($form_state['values']['type'] == 'move') {
      // If it's a move, all we have to do is update the changeset_id in the cps_entity table.
      db_update('cps_entity')
        ->condition('entity_type', $entity_type)
        ->condition('revision_id', $revision_id)
        ->condition('changeset_id', $changeset_from->changeset_id)
        ->fields(array(
          'changeset_id' => $changeset_to,
        ))
        ->execute();
    }
    else {
      // Otherwise we need to resave the entity in the current changeset. It was loaded from
      // the prior changeset already, so it should already have the correct changes.
      entity_save($entity_type, $entity);
    }
    drupal_set_message(t('Changes to %entity have been @type from the site version %title to the current site version.', array(
      '%entity' => entity_label($entity_type, $entity),
      '%title' => $changeset_from->label(),
      '@type' => $form_state['values']['type'] == 'move' ? t('moved') : t('copied')
    )));
    $form_state['redirect'] = $changeset_from->uri();
  }
}
