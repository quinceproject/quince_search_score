<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Quince\\SearchScore\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

use Quince\SearchScore\Repository;

/**
 * Quince Search Score — co klienci szukają i w co klikają po wyszukaniu.
 *
 * Port `flavor_search_score` (inny silnik): dwie tabele, śledzenie fraz
 * i kliknięć z frontu (bez logowania, publiczny endpoint), ręczny score
 * produktu w panelu — każde kliknięcie z kontekstu wyszukiwania dolicza +1,
 * obsługa może go też nadpisać wprost.
 *
 * Stoi obok `quince_search`, nie w nim — osobny moduł, tak jak
 * `quince_withdrawal` czy `quince_events`: to gromadzenie i przegląd danych
 * (lista, filtrowanie, edycja), nie ustawienie samej wyszukiwarki.
 * `quince_search` czyta stąd `score` jako opcjonalny, słaby sygnał
 * (rozstrzyga remisy trafności, nie przebija jej) — tylko gdy ten moduł
 * jest zainstalowany.
 */
class Quince_Search_Score extends Module
{
    public const DOMAIN_ADMIN = 'Modules.Quincesearchscore.Admin';

    public function __construct()
    {
        $this->name = 'quince_search_score';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->dependencies = ['quince_engine'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Quince Search Score', [], self::DOMAIN_ADMIN);
        $this->description = $this->trans(
            'Tracks search phrases and the products customers click after searching. Lets staff set a manual score that nudges search ranking.',
            [],
            self::DOMAIN_ADMIN
        );
    }

    public function install(): bool
    {
        return parent::install()
            && (new Repository())->installTables()
            && $this->installTabs()
            && $this->registerHook('displayHeader');
    }

    public function uninstall(): bool
    {
        (new Repository())->dropTables();

        return $this->uninstallTabs() && parent::uninstall();
    }

    private function installTabs(): bool
    {
        if (Tab::getIdFromClassName('AdminQuinceSearchScore')) {
            return true;
        }

        $idParent = (int) Tab::getIdFromClassName('AdminQuinceProject');

        $tab = new Tab();
        $tab->class_name = 'AdminQuinceSearchScore';
        $tab->module = $this->name;
        $tab->id_parent = $idParent > 0 ? $idParent : -1;
        $tab->route_name = 'quince_search_score';
        $tab->active = 1;
        $tab->icon = 'trending_up';
        $tab->wording = 'Search Score';
        $tab->wording_domain = self::DOMAIN_ADMIN;

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Search Score';
        }

        return (bool) $tab->add();
    }

    private function uninstallTabs(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminQuinceSearchScore');

        if ($idTab <= 0) {
            return true;
        }

        $tab = new Tab($idTab);

        return (bool) $tab->delete();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('quince_search_score'));
    }

    /**
     * Tracker frontowy na każdej stronie sklepu — wykrywa stronę wyników sama,
     * nic nie trzeba wołać z szablonu.
     */
    public function hookDisplayHeader(): void
    {
        $trackUrl = $this->context->link->getModuleLink($this->name, 'track', [], true);

        Media::addJsDef(['qssTrackUrl' => $trackUrl]);
        $this->context->controller->registerJavascript(
            'module-qss-tracker',
            'modules/' . $this->name . '/views/js/search-score-tracker.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }
}
