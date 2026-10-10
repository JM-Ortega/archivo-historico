<form
  id="search-box"
  class="w-100 mw-100 m-0"
  role="search"
  action="<?php echo url_for(['module' => 'informationobject', 'action' => 'browse']); ?>">
  <input type="hidden" name="topLod" value="0">
  <input type="hidden" name="sort" value="relevance">
  <div class="d-flex flex-column flex-sm-row gap-3">
    <div class="position-relative flex-grow-1">
      <i class="home-search-icon fas fa-search position-absolute top-50 start-0 translate-middle-y ms-3 pe-none" aria-hidden="true"></i>
      <input
        id="search-box-input"
        class="home-search-input form-control py-3 ps-5 rounded-3 dropdown-toggle"
        type="search"
        name="query"
        autocomplete="off"
        value="<?php echo $sf_request->query; ?>"
        placeholder="Ej. Simón Bolívar, Cabildo de Popayán..."
        data-url="<?php echo url_for(['module' => 'search', 'action' => 'autocomplete']); ?>"
        data-bs-toggle="dropdown"
        aria-expanded="false">
      <ul id="search-box-results" class="dropdown-menu w-100 mt-2" aria-labelledby="search-box-input"></ul>
    </div>
    <button class="home-search-btn btn fw-bold rounded-3 d-inline-flex align-items-center justify-content-center gap-2" type="submit">
      <i class="fas fa-search" aria-hidden="true"></i>
      Buscar
    </button>
  </div>
</form>
