<?php
    $options = [
        $cardView => ['label' => __('Vista de tarjetas'), 'icon' => 'fa-th-large'],
        $tableView => ['label' => __('Vista de tabla'), 'icon' => 'fa-list'],
    ];
    $active = isset($options[$view]) ? $view : $cardView;
?>

<div class="dropdown d-inline-block">
  <button class="btn btn-sm atom-btn-white dropdown-toggle text-wrap" type="button" id="view-button" data-bs-toggle="dropdown" aria-expanded="false">
    <i class="fas <?php echo $options[$active]['icon']; ?> me-1" aria-hidden="true"></i>
    <?php echo $options[$active]['label']; ?>
  </button>
  <ul class="dropdown-menu mt-2" aria-labelledby="view-button">
    <?php foreach ($options as $key => $item) { ?>
      <li>
        <a
          href="<?php echo url_for(
              ['module' => $module, 'action' => 'browse', 'view' => $key]
              + $sf_data->getRaw('sf_request')->getParameterHolder()->getAll()
          ); ?>"
          class="dropdown-item<?php echo $active === $key ? ' active' : ''; ?>"
          <?php echo $active === $key ? 'aria-current="page"' : ''; ?>>
          <i class="fas <?php echo $item['icon']; ?> me-2" aria-hidden="true"></i><?php echo $item['label']; ?>
        </a>
      </li>
    <?php } ?>
  </ul>
</div>
