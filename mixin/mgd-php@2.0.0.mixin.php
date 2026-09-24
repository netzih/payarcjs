<?php

/**
 * Auto-register managed entity definition files.
 *
 * @mixinName mgd-php
 * @mixinVersion 2.0.0
 * @since 6.9
 */
return function ($mixInfo, $bootCache) {
  Civi::dispatcher()->addListener('hook_civicrm_managed', function ($event) use ($mixInfo) {
    if (!$mixInfo->isActive()) {
      return;
    }
    if ($event->modules && !in_array($mixInfo->longName, $event->modules, TRUE)) {
      return;
    }

    $path = $mixInfo->getPath();
    $managedFiles = array_merge(
      (array) glob("$path/*.mgd.php"),
      CRM_Utils_File::findFiles("$path/managed", '*.mgd.php'),
      CRM_Utils_File::findFiles("$path/api", '*.mgd.php'),
      CRM_Utils_File::findFiles("$path/CRM", '*.mgd.php'),
      CRM_Utils_File::findFiles("$path/Civi", '*.mgd.php')
    );

    sort($managedFiles);
    foreach ($managedFiles as $file) {
      foreach ((array) include $file as $entity) {
        $entity['module'] ??= $mixInfo->longName;
        $entity['params']['version'] ??= 3;
        $entity['source'] ??= $file;
        $event->entities[] = $entity;
      }
    }
  });
};
