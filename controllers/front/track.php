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

/**
 * Quince Search Score: endpoint śledzenia (AJAX, publiczny, bez logowania).
 *
 * Dwa typy zdarzeń, każdy wstawia nowy wiersz albo dolicza do istniejącego:
 *   1) phrase: fraza wyszukana (dolicza `count`)
 *   2) click:  kliknięcie w produkt z kontekstu wyszukiwania (dolicza `score`)
 *
 * URL: ?fc=module&module=quince_search_score&controller=track
 *
 * Bez CSRF celowo: to publiczny, anonimowy endpoint instrumentacji,
 * nie formularz panelu.
 */
class Quince_Search_ScoreTrackModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** Klucz licznika w strażniku formularzy silnika, osobny od formularzy sklepu. */
    private const LIMIT_KEY = 'quince_search_score_track';

    /** Ile zdarzeń (fraz i kliknięć razem) z jednego adresu w oknie poniżej. */
    private const LIMIT_COUNT = 120;

    /** Okno licznika w sekundach. */
    private const LIMIT_WINDOW = 600;

    /** Jak długo po wyszukaniu frazy liczymy kliknięcia tego odwiedzającego (sekundy). */
    private const CLICK_WINDOW = 1800;

    /** Ile produktów kliknięcia dziennie pamiętamy w ciasteczku jednego odwiedzającego. */
    private const CLICK_MEMORY = 50;

    /** Kiedy ostatnio sprzątaliśmy jednorazowe frazy (znacznik czasu). */
    public const LAST_PURGE = 'QUINCE_SEARCH_SCORE_LAST_PURGE';

    /** Po ilu dniach znika fraza wyszukana tylko raz. */
    private const PHRASE_KEEP_DAYS = 90;

    public function initContent()
    {
        if (!$this->isXmlHttpRequest() || Tools::getValue('type') === false) {
            $this->jsonError('Invalid request', 400);
        }

        // Ten sam strażnik co przy podpowiedziach quince_search: skrót adresu
        // w tabeli silnika, okno dziesięciu minut. Limit jest liberalny, bo za
        // jednym adresem stoi czasem całe biuro; ma zatrzymać nabijanie licznika,
        // a nie klienta. Odmowa wygląda jak przyjęte zdarzenie, które się nie
        // policzyło: przeglądarka klienta nie ma tu czego naprawiać.
        $straznik = new \Quince\Engine\Form\FormGuard();

        if ($straznik->tooOften(self::LIMIT_KEY, self::LIMIT_COUNT, self::LIMIT_WINDOW)) {
            $this->jsonSuccess(['tracked' => false]);
        }

        $straznik->accept(self::LIMIT_KEY);
        $this->purgeOldPhrases();

        $type = Tools::getValue('type');

        switch ($type) {
            case 'phrase':
                $this->trackPhrase();
                break;
            case 'click':
                $this->trackClick();
                break;
            default:
                $this->jsonError('Unknown type', 400);
        }
    }

    private function trackPhrase(): void
    {
        $phrase = trim((string) Tools::getValue('phrase', ''));

        if ($phrase === '' || mb_strlen($phrase) > 255) {
            $this->jsonError('Invalid phrase', 400);
        }

        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $escapedPhrase = pSQL(mb_strtolower($phrase));

        $existing = (int) $db->getValue(
            'SELECT `id_phrase` FROM `' . _DB_PREFIX_ . 'quince_search_score_phrases`
             WHERE `phrase` = \'' . $escapedPhrase . '\''
        );

        if ($existing) {
            $db->update('quince_search_score_phrases', [
                'count' => ['type' => 'sql', 'value' => '`count` + 1'],
                'date_upd' => $now,
            ], '`id_phrase` = ' . $existing);
        } else {
            $db->insert('quince_search_score_phrases', [
                'phrase' => $escapedPhrase,
                'count' => 1,
                'date_add' => $now,
                'date_upd' => $now,
            ]);
        }

        // Ślad wyszukania w ciasteczku sklepu (szyfrowanym, więc przeglądarka
        // go nie podrobi): bez niego kliknięcie nie podbije wyniku produktu.
        $this->context->cookie->qss_phrase_at = (string) time();
        $this->context->cookie->write();

        $this->jsonSuccess(['tracked' => 'phrase']);
    }

    /**
     * Kliknięcie w produkt z wyników wyszukiwania.
     *
     * Wynik produktu decyduje o sekcji „najczęściej szukane" na stronie
     * głównej, więc liczymy tylko kliknięcia odwiedzającego, który przed
     * chwilą coś wyszukał, i tylko jedno na produkt dziennie. Odmowa wygląda
     * jak przyjęte zdarzenie, które się nie policzyło.
     */
    private function trackClick(): void
    {
        $idProduct = (int) Tools::getValue('id_product', 0);

        if ($idProduct <= 0) {
            $this->jsonError('Invalid id_product', 400);
        }

        $cookie = $this->context->cookie;

        if ((int) $cookie->qss_phrase_at < time() - self::CLICK_WINDOW) {
            $this->jsonSuccess(['tracked' => false]);
        }

        // Tylko produkt, który istnieje i jest włączony w tym sklepie.
        $exists = (int) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`
             WHERE `id_product` = ' . $idProduct . '
               AND `id_shop` = ' . (int) $this->context->shop->id . '
               AND `active` = 1'
        );

        if (!$exists) {
            $this->jsonError('Product not found', 404);
        }

        // Kliknięte dziś produkty: „dzień|id,id,...". Nowy dzień czyści listę.
        $dzis = date('Ymd');
        [$dzien, $lista] = array_pad(explode('|', (string) $cookie->qss_clicks, 2), 2, '');
        $klikniete = $dzien === $dzis && $lista !== '' ? array_map('intval', explode(',', $lista)) : [];

        if (in_array($idProduct, $klikniete, true)) {
            $this->jsonSuccess(['tracked' => false]);
        }

        $klikniete[] = $idProduct;
        $cookie->qss_clicks = $dzis . '|' . implode(',', array_slice($klikniete, -self::CLICK_MEMORY));
        $cookie->write();

        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');

        $existing = (int) $db->getValue(
            'SELECT `id_score` FROM `' . _DB_PREFIX_ . 'quince_search_score_products`
             WHERE `id_product` = ' . $idProduct
        );

        if ($existing) {
            $db->update('quince_search_score_products', [
                'score' => ['type' => 'sql', 'value' => '`score` + 1'],
                'date_upd' => $now,
            ], '`id_score` = ' . $existing);
        } else {
            $db->insert('quince_search_score_products', [
                'id_product' => $idProduct,
                'score' => 1,
                'date_add' => $now,
                'date_upd' => $now,
            ]);
        }

        $this->jsonSuccess(['tracked' => 'click', 'id_product' => $idProduct]);
    }

    /**
     * Frazy wyszukane tylko raz i dawno, najwyżej raz na dobę. Fraza bywa
     * nazwiskiem albo numerem zamówienia, więc nie trzymamy jej bez końca;
     * frazy powtarzane zostają, bo z nich panel czyta trendy.
     */
    private function purgeOldPhrases(): void
    {
        if ((int) Configuration::getGlobalValue(self::LAST_PURGE) > time() - 86400) {
            return;
        }

        Configuration::updateGlobalValue(self::LAST_PURGE, (string) time());

        Db::getInstance()->delete(
            'quince_search_score_phrases',
            '`count` = 1 AND `date_upd` < "' . date('Y-m-d H:i:s', time() - self::PHRASE_KEEP_DAYS * 86400) . '"'
        );
    }

    private function jsonSuccess(array $data = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(array_merge(['success' => true], $data)));
    }

    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'error' => $message]));
    }
}
