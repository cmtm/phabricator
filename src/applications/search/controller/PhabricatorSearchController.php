<?php

final class PhabricatorSearchController
  extends PhabricatorSearchBaseController {

  const SCOPE_CURRENT_APPLICATION = 'application';

  private $queryKey;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->queryKey = idx($data, 'queryKey');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    if ($request->getStr('jump') != 'no') {
      $pref_jump = PhabricatorUserPreferences::PREFERENCE_SEARCHBAR_JUMP;
      if ($viewer->loadPreferences($pref_jump, 1)) {
        $response = PhabricatorJumpNavHandler::getJumpResponse(
          $viewer,
          $request->getStr('query'));
        if ($response) {
          return $response;
        }
      }
    }

    $engine = new PhabricatorSearchApplicationSearchEngine();
    $engine->setViewer($viewer);

    // If we're coming from primary search, do some special handling to
    // interpret the scope selector and query.
    if ($request->getBool('search:primary')) {

      // If there's no query, just take the user to advanced search.
      if (!strlen($request->getStr('query'))) {
        $advanced_uri = '/search/query/advanced/';
        return id(new AphrontRedirectResponse())->setURI($advanced_uri);
      }

      // First, load or construct a template for the search by examining
      // the current search scope.
      $scope = $request->getStr('search:scope');
      $saved = null;

      if ($scope == self::SCOPE_CURRENT_APPLICATION) {
        $application = id(new PhabricatorApplicationQuery())
          ->setViewer($viewer)
          ->withClasses(array($request->getStr('search:application')))
          ->executeOne();
        if ($application) {
          $types = $application->getApplicationSearchDocumentTypes();
          if ($types) {
            $saved = id(new PhabricatorSavedQuery())
              ->setEngineClassName(get_class($engine))
              ->setParameter('types', $types);
          }
        }
      }

      if (!$saved && !$engine->isBuiltinQuery($scope)) {
        $saved = id(new PhabricatorSavedQueryQuery())
          ->setViewer($viewer)
          ->withQueryKeys(array($scope))
          ->executeOne();
      }

      if (!$saved) {
        if (!$engine->isBuiltinQuery($scope)) {
          $scope = 'all';
        }
        $saved = $engine->buildSavedQueryFromBuiltin($scope);
      }

      // Add the user's query, then save this as a new saved query and send
      // the user to the results page.
      $saved->setParameter('query', $request->getStr('query'));

      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
        try {
          $saved->setID(null)->save();
        } catch (AphrontDuplicateKeyQueryException $ex) {
          // Ignore, this is just a repeated search.
        }
      unset($unguarded);

      $query_key = $saved->getQueryKey();
      $results_uri = $engine->getQueryResultsPageURI($query_key).'#R';
      return id(new AphrontRedirectResponse())->setURI($results_uri);
    }

    $controller = id(new PhabricatorApplicationSearchController())
      ->setQueryKey($this->queryKey)
      ->setSearchEngine($engine)
      ->setNavigation($this->buildSideNavView());

    return $this->delegateToController($controller);
  }

  public function buildSideNavView($for_app = false) {
    $viewer = $this->getRequest()->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    id(new PhabricatorSearchApplicationSearchEngine())
      ->setViewer($viewer)
      ->addNavigationItems($nav->getMenu());

    $nav->selectFilter(null);

    return $nav;
  }

}
