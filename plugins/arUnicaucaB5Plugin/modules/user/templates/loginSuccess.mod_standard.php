<?php decorate_with('layout_1col'); ?>

<?php slot('content'); ?>
  <div class="ah-login">
    <section class="ah-login-brand" aria-labelledby="ah-login-brand-title">
      <div class="ah-login-brand-header">
        <span class="ah-login-brand-rule" aria-hidden="true"></span>
        <div>
          <div class="ah-login-kicker">Patrimonio documental</div>
          <div class="ah-login-brand-name">Archivo Histórico del Cauca</div>
        </div>
      </div>

      <div class="ah-login-message">
        <div class="ah-login-kicker">Memoria abierta</div>
        <h1 id="ah-login-brand-title">Conservamos la<br class="ah-login-desktop-break"> memoria histórica del Cauca</h1>
        <span class="ah-login-accent" aria-hidden="true"></span>
        <p>Preservamos y ponemos a disposición el patrimonio documental que da testimonio de nuestra historia.</p>
      </div>

      <div class="ah-login-legal">© <?php echo date('Y'); ?> Archivo Histórico del Cauca <span aria-hidden="true">·</span> Popayán, Cauca, Colombia</div>
    </section>

    <section class="ah-login-panel" aria-labelledby="ah-login-title">
      <div class="ah-login-form-container">
        <?php echo $form->renderGlobalErrors(); ?>
        <h2 id="ah-login-title">Iniciar sesión</h2>
        <p class="ah-login-subtitle">Acceda al sistema con sus credenciales.</p>

        <?php echo $form->renderFormTag(url_for(['module' => 'user', 'action' => 'login']), ['class' => 'ah-login-form']); ?>
          <?php echo $form->renderHiddenFields(); ?>

          <div class="ah-login-field">
            <label for="email">Correo electrónico <span aria-hidden="true">*</span></label>
            <?php echo $form->email->render([
                'id' => 'email',
                'class' => 'form-control',
                'type' => 'email',
                'autofocus' => 'autofocus',
                'required' => 'required',
                'placeholder' => 'nombre@correo.com',
                'autocomplete' => 'email',
            ]); ?>
            <?php echo $form->email->renderError(); ?>
          </div>

          <div class="ah-login-field">
            <label for="password">Contraseña <span aria-hidden="true">*</span></label>
            <?php echo $form->password->render([
                'id' => 'password',
                'class' => 'form-control',
                'type' => 'password',
                'required' => 'required',
                'placeholder' => 'Ingrese su contraseña',
                'autocomplete' => 'current-password',
            ]); ?>
            <?php echo $form->password->renderError(); ?>
          </div>

          <button type="submit" class="ah-login-submit">Iniciar sesión</button>
        </form>

        <button type="button" class="ah-login-forgot" data-ah-login-forgot aria-expanded="false" aria-controls="ah-login-recovery">
          ¿Olvidó su contraseña?
        </button>
        <div id="ah-login-recovery" class="ah-login-recovery" hidden>
          Comuníquese con el equipo del Archivo Histórico del Cauca para recuperar su acceso.
          <br>
          Correo: <a href="mailto:archivoh@unicauca.edu.co">archivoh@unicauca.edu.co</a>
        </div>
      </div>
    </section>
  </div>
<?php end_slot(); ?>
