<?php if (isset($hasPrevSiblings) && $hasPrevSiblings) { ?>
  <?php echo arUnicaucaTreeCounts::node(
    null,
    ['more' => true],
    ['xhr-location' => url_for([$items[0], 'module' => 'informationobject', 'action' => 'treeView']),
        'numSiblingsLeft' => $siblingCountPrev, ]); ?>

<?php } ?>

<?php foreach ($items as $item) { ?>
  <?php echo arUnicaucaTreeCounts::node(
    $item,
    ['expand' => 1 < $item->rgt - $item->lft, 'active' => $sf_request->resourceId == $item->id],
    ['xhr-location' => url_for([$item, 'module' => 'informationobject', 'action' => 'treeView'])]); ?>
<?php } ?>

<?php if (isset($hasNextSiblings) && $hasNextSiblings) { ?>
  <?php echo arUnicaucaTreeCounts::node(
    null,
    ['more' => true],
    ['xhr-location' => url_for([$item, 'module' => 'informationobject', 'action' => 'treeView']),
        'numSiblingsLeft' => $siblingCountNext, ]); ?>

<?php } ?>
