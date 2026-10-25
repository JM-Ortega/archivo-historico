<?php
/*
 * Contenido fijo de «Acerca de la plataforma».
 * Estilos: scss/_about.scss (.ah-about*). Los textos se editan aquí, no desde la interfaz de AtoM.
 */
$paragraphs = [
    'Esta es la plataforma web del Archivo Histórico, diseñada para facilitar la organización, gestión y consulta de las descripciones archivísticas, promoviendo un acceso más eficiente y organizado al patrimonio documental.',
    'La plataforma permite administrar las descripciones de los documentos y fondos documentales, así como realizar búsquedas mediante diferentes criterios para facilitar la localización de la información de interés. De esta manera, se busca mejorar los procesos de catalogación, reducir los errores asociados a la gestión manual y brindar a investigadores, estudiantes y demás usuarios una herramienta que facilite la exploración y consulta de los recursos archivísticos.',
    'Este sistema contribuye a la modernización de los procesos del Archivo Histórico, favoreciendo la organización, disponibilidad y difusión de la información documental, y fortaleciendo el acceso al patrimonio histórico del Cauca.',
];
?>
<article class="ah-about">
  <header class="ah-about__header">
    <p class="ah-about__eyebrow">Información institucional</p>
    <h1 class="ah-about__title">Acerca de la plataforma</h1>
  </header>

  <section class="ah-about__panel">
    <div class="ah-about__panel-head">
      <p class="ah-about__kicker">Archivo Histórico del Cauca</p>
    </div>
    <div class="ah-about__body">
      <?php foreach ($paragraphs as $paragraph) { ?>
        <p><?php echo $paragraph; ?></p>
      <?php } ?>
    </div>
  </section>
</article>
