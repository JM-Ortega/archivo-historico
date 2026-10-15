<?php decorate_with('layout_2col'); ?>

<?php slot('sidebar'); ?>
  <section class="home-menu card rounded-4 shadow-sm mb-3">
    <h2 class="home-menu-title fw-bold p-3 mb-0"><?php echo __('Opciones de búsqueda'); ?></h2>
    <div class="list-group list-group-flush">
      <a
        class="list-group-item list-group-item-action"
        href="<?php echo url_for('@homepage'); ?>#search-box-input">
        <i class="fas fa-search me-2" aria-hidden="true"></i>
        Búsqueda básica
      </a>
      <a
        class="list-group-item list-group-item-action"
        href="<?php echo url_for([
            'module' => 'informationobject',
            'action' => 'browse',
            'showAdvanced' => true,
            'topLod' => false,
        ]); ?>">
        <i class="fas fa-sliders-h me-2" aria-hidden="true"></i>
        Búsqueda avanzada
      </a>
      <a
        class="list-group-item list-group-item-action active"
        aria-current="page"
        href="<?php echo url_for(['module' => 'user', 'action' => 'list']); ?>">
        <i class="fas fa-users me-2" aria-hidden="true"></i>
        Usuarios
      </a>
    </div>
  </section>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <?php
    $selectedFilter = isset($_REQUEST['filter']) ? trim((string) $_REQUEST['filter']) : 'all';
    if ('' === $selectedFilter) {
        $selectedFilter = 'all';
    }
    $filteredUsers = [];
    $activeUsers = 0;
    $administratorUsers = 0;
    foreach ($users as $user) {
        $isActive = '1' === (string) $user->active || true === $user->active;
        if ($isActive) {
            ++$activeUsers;
        }
        foreach ($user->getAclGroups() as $group) {
            if (false !== stripos((string) render_title($group), 'admin')) {
                ++$administratorUsers;
                break;
            }
        }

        if (
            ('onlyActive' === $selectedFilter && !$isActive)
            || ('onlyInactive' === $selectedFilter && true === $isActive)
        ) {
            continue;
        }

        $filteredUsers[] = $user;
    }
  ?>
  <div class="users-view">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <div class="users-eyebrow">Administración del sistema</div>
        <h1 class="users-title mb-2"><?php echo __('List users'); ?></h1>
        <p class="users-lead mb-0">Consulte las cuentas registradas, sus roles de acceso y el estado actual dentro del sistema.</p>
      </div>
      <?php echo link_to(
          '<i class="fas fa-user-plus me-2" aria-hidden="true"></i>Crear nuevo usuario',
          ['module' => 'user', 'action' => 'add'],
          ['class' => 'users-create-user btn rounded-pill px-4']
      ); ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="users-stat d-flex align-items-center gap-3 p-3">
          <span class="users-stat-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
          <div><div class="users-stat-value"><?php echo count($users); ?></div><div class="users-stat-label">Usuarios registrados</div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="users-stat d-flex align-items-center gap-3 p-3">
          <span class="users-stat-icon"><i class="fas fa-circle" aria-hidden="true"></i></span>
          <div><div class="users-stat-value"><?php echo $activeUsers; ?></div><div class="users-stat-label">Cuentas activas</div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="users-stat d-flex align-items-center gap-3 p-3">
          <span class="users-stat-icon"><i class="fas fa-lock" aria-hidden="true"></i></span>
          <div><div class="users-stat-value"><?php echo $administratorUsers; ?></div><div class="users-stat-label">Administradores</div></div>
        </div>
      </div>
    </div>

    <div class="users-table-card">
      <div class="users-table-toolbar p-3">
        <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
          <?php echo get_component('search', 'inlineSearch', [
              'label' => __('Search users'),
              'landmarkLabel' => __('User'),
              'route' => url_for(['module' => 'user', 'action' => 'list']),
          ]); ?>
          <nav aria-label="Filtros de usuarios">
            <ul class="nav nav-pills mb-0 d-flex gap-2">
              <li class="nav-item">
                <?php echo link_to(
                    'Todos',
                    ['module' => 'user', 'action' => 'list', 'filter' => 'all']
                    + ($sf_request->subquery ? ['subquery' => $sf_request->subquery] : []),
                    [
                        'class' => 'btn atom-btn-white active-primary text-wrap'.('all' === $selectedFilter ? ' active' : ''),
                        'aria-current' => 'all' === $selectedFilter ? 'page' : null,
                    ]
                ); ?>
              </li>
              <li class="nav-item">
                <?php echo link_to(
                    'Activo',
                    ['module' => 'user', 'action' => 'list', 'filter' => 'onlyActive']
                    + ($sf_request->subquery ? ['subquery' => $sf_request->subquery] : []),
                    [
                        'class' => 'btn atom-btn-white active-primary text-wrap'.('onlyActive' === $selectedFilter ? ' active' : ''),
                        'aria-current' => 'onlyActive' === $selectedFilter ? 'page' : null,
                    ]
                ); ?>
              </li>
              <li class="nav-item">
                <?php echo link_to(
                    'Inactivo',
                    ['module' => 'user', 'action' => 'list', 'filter' => 'onlyInactive']
                    + ($sf_request->subquery ? ['subquery' => $sf_request->subquery] : []),
                    [
                        'class' => 'btn atom-btn-white active-primary text-wrap'.('onlyInactive' === $selectedFilter ? ' active' : ''),
                        'aria-current' => 'onlyInactive' === $selectedFilter ? 'page' : null,
                    ]
                ); ?>
              </li>
            </ul>
          </nav>
        </div>
      </div>
      <div class="table-responsive">
        <table class="users-table table mb-0">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Rol</th>
              <th>Estado</th>
              <th>Último acceso</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filteredUsers as $item) { ?>
              <?php
                $groups = $item->getAclGroups();
                if ($item->hasGroup(QubitAclGroup::ADMINISTRATOR_ID)) {
                    $role = 'Administrador';
                } elseif ($item->hasGroup(QubitAclGroup::EDITOR_ID)) {
                    $role = 'Editor';
                } else {
                    $role = 'Usuario autenticado';
                }
                $name = trim((string) $item->username);
                $initials = strtoupper(substr($name, 0, 2));
              ?>
              <tr>
                <td>
                  <div class="user-identity">
                    <span class="user-avatar"><?php echo esc_entities($initials); ?></span>
                    <div>
                      <div class="user-name"><?php echo link_to($name, [$item, 'module' => 'user']); ?></div>
                      <div class="user-email"><?php echo esc_entities($item->email); ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="user-role"><?php echo esc_entities($role); ?></span></td>
                <td><span class="user-status<?php echo $item->active ? '' : ' inactive'; ?>"><?php echo $item->active ? 'Activo' : 'Inactivo'; ?></span></td>
                <td>—</td>
                <td><?php echo link_to('Editar permisos', [$item, 'module' => 'user', 'action' => 'edit'], ['class' => 'users-action']); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php echo get_partial('default/pager', ['pager' => $pager]); ?>
  </div>
<?php end_slot(); ?>
