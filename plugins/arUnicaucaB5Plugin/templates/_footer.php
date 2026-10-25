<footer class="d-print-none ah-site-footer">
  <?php if (QubitAcl::check('userInterface', 'translate')) { ?>
    <?php echo get_component('sfTranslatePlugin', 'translate'); ?>
  <?php } ?>

  <?php echo get_component_slot('footer'); ?>

  <div class="ah-site-footer-content">
    <div class="ah-site-footer-column ah-site-footer-intro">
      <span class="ah-site-footer-kicker">Patrimonio documental</span>
      <h2>Archivo Histórico del Cauca</h2>
      <span class="ah-site-footer-rule"></span>
      <p>Memoria, patrimonio y conocimiento al servicio de la ciudadanía y la investigación.</p>
    </div>

    <div class="ah-site-footer-column ah-site-footer-contact">
      <h2><i class="fas fa-envelope" aria-hidden="true"></i> Contacto</h2>
      <dl>
        <dt>Archivo</dt>
        <dd><a href="mailto:archivoh@unicauca.edu.co">archivoh@unicauca.edu.co</a></dd>
        <dt>Coordinadora</dt>
        <dd>Beatriz Quintero Espinosa</dd>
        <dd><a href="mailto:bequintero@unicauca.edu.co">bequintero@unicauca.edu.co</a></dd>
      </dl>
    </div>

    <div class="ah-site-footer-column ah-site-footer-address">
      <h2><i class="fas fa-map-marker-alt" aria-hidden="true"></i> Dirección</h2>
      <address>
        <strong>Facultad de Ciencias Humanas y Sociales</strong>
        Calle 3 No. 5-38<br>
        Popayán, Cauca
      </address>
    </div>
  </div>

  <div class="ah-site-footer-bottom">
    <span>© <?php echo date('Y'); ?> Archivo Histórico del Cauca</span>
    <span>Memoria del Mundo de América Latina y el Caribe</span>
  </div>

  <div id="print-date">
    <?php echo __('Printed: %d%', ['%d%' => date('Y-m-d')]); ?>
  </div>

  <div id="js-i18n">
    <div id="read-more-less-links"
      data-read-more-text="<?php echo __('Read more'); ?>"
      data-read-less-text="<?php echo __('Read less'); ?>">
    </div>
  </div>
</footer>
