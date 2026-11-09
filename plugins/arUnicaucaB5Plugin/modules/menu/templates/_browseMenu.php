<div class="dropdown my-2 me-3">
  <button class="btn btn-sm atom-btn-secondary dropdown-toggle" type="button" id="browse-menu" data-bs-toggle="dropdown" aria-expanded="false">
    <?php echo $browseMenu->getLabel(['cultureFallback' => true]); ?>
  </button>
  <ul class="dropdown-menu mt-2" aria-labelledby="browse-menu">
    <li>
      <h6 class="dropdown-header">
        <?php echo $browseMenu->getLabel(['cultureFallback' => true]); ?>
      </h6>
    </li>
    <?php /* OCULTO-004
       SERVÍA PARA: abrir el listado de todos los registros de autoridad (personas, familias y entidades) desde el botón «Navegar».
       MOTIVO: el archivo no maneja información de registros de autoridad en este prototipo.
       Código original:
       <?php if ($child->checkUserAccess()) { ?>
    */ ?>
    <?php /* OCULTO-011
         SERVÍA PARA: abrir el listado de instituciones archivísticas desde el botón «Navegar».
         MOTIVO: el archivo es una sola institución y no maneja este módulo; confunde y satura las opciones.
         Código original:
         <?php if ($child->checkUserAccess() && 'browseActors' != $child->getName()) { ?>
      */ ?>
    <?php foreach ($browseMenu->getChildren() as $child) { ?>
      <?php /* OCULTO-025
        SERVÍA PARA: abrir el listado de funciones (norma ISDF) desde el botón «Navegar».
        MOTIVO: el archivo histórico no maneja funciones; no las usa.
        Código original: ninguno; el ítem se mostraba a todo usuario con acceso (no había condición que lo excluyera).
      */ ?>
      <?php if ('browseFunctions' === $child->getName()) { continue; } ?>
      <?php /* OCULTO-026
        SERVÍA PARA: abrir la lista de materias (temas) desde el botón «Navegar».
        MOTIVO: el archivo histórico no maneja materias; no las usa.
        Código original: ninguno; el ítem se mostraba a todo usuario con acceso (no había condición que lo excluyera).
      */ ?>
      <?php if ('browseSubjects' === $child->getName()) { continue; } ?>
      <?php /* OCULTO-027
        SERVÍA PARA: abrir el listado de objetos digitales desde el botón «Navegar».
        MOTIVO: el sistema solo guarda descripciones, no los documentos digitalizados.
        Código original: ninguno; el ítem se mostraba a todo usuario con acceso (no había condición que lo excluyera).
      */ ?>
      <?php if ('browseDigitalObjects' === $child->getName()) { continue; } ?>
      <?php if ($child->checkUserAccess() && 'browseActors' != $child->getName() && 'browseRepositories' != $child->getName()) { ?>
        <li <?php echo isset($child->name) ? 'id="node_'.$child->name.'"' : ''; ?>>
          <?php echo link_to(
              $child->getLabel(['cultureFallback' => true]),
              $child->getPath(['getUrl' => true, 'resolveAlias' => true]),
              ['class' => 'dropdown-item']
          ); ?>
        </li>
      <?php } ?>
    <?php } ?>
  </ul>
</div>
