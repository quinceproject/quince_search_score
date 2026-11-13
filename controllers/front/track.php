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

        $this->jsonSuccess(['tracked' => 'phrase']);
    }

    private function trackClick(): void
    {
        $idProduct = (int) Tools::getValue('id_product', 0);

        if ($idProduct <= 0) {
            $this->jsonError('Invalid id_product', 400);
        }

        $exists = (int) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct
        );

        if (!$exists) {
            $this->jsonError('Product not found', 404);
        }

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
