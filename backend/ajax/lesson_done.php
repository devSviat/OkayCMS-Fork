<?php

require_once 'configure.php';

use Okay\Entities\LessonsEntity;

if (!$request->method('post') || !$request->checkSession() || !$managers->access('learning', $manager)) {
    exit();
}

$lessonsEntity = $entityFactory->get(LessonsEntity::class);
$lessonsEntity->update($request->post('lesson', 'integer'), [
    'done' => 1
]);
