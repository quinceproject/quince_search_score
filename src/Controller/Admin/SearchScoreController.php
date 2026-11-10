<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\SearchScore\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Quince\SearchScore\Repository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel: co klienci szukają (Phrases) i w co klikają po wyszukaniu, z ręcznym
 * score produktu (Products). Jeden kontroler, dwie zakładki — tak jak
 * w oryginale (`AdminFlavorSearchScoreController`), żeby filtr/sort/strona
 * żyły w jednym miejscu zamiast w dwóch prawie identycznych kopiach.
 *
 * Stan filtrów podróżuje w query stringu, nie w ukrytych polach formularza:
 * akcje POST (`updateScoreAction`, `bulkDelete*Action`) budują swój `action=`
 * z tym samym query stringiem, który dostały na wejściu, i oddają go
 * z powrotem przy przekierowaniu — strona wraca dokładnie tam, gdzie była.
 */
final class SearchScoreController extends FrameworkBundleAdminController
{
    private const DOMAIN = 'Modules.Quincesearchscore.Admin';
    private const PER_PAGE_OPTIONS = [10, 20, 50, 100];
    private const PRODUCT_SORTABLE = ['id_product', 'name', 'category', 'score'];
    private const PHRASE_SORTABLE = ['id_phrase', 'phrase', 'count', 'date_upd'];

    public function __construct(private readonly Repository $repository)
    {
    }

    public function indexAction(Request $request): Response
    {
        $tab = $request->query->get('tab', 'products') === 'phrases' ? 'phrases' : 'products';
        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = in_array((int) $request->query->get('per_page', 50), self::PER_PAGE_OPTIONS, true)
            ? (int) $request->query->get('per_page', 50)
            : 50;

        $allowedSort = $tab === 'products' ? self::PRODUCT_SORTABLE : self::PHRASE_SORTABLE;
        $orderBy = (string) $request->query->get('order_by', $tab === 'products' ? 'score' : 'count');
        if (!in_array($orderBy, $allowedSort, true)) {
            $orderBy = $tab === 'products' ? 'score' : 'count';
        }
        $orderDir = strtoupper((string) $request->query->get('order_dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $idLang = (int) $this->getContext()->language->id;
        $idShop = (int) $this->getContext()->shop->id;

        // Query string wspólny dla sortowania/stronicowania i dla akcji POST —
        // ta sama struktura wraca w każdym linku i w każdym `action=`.
        $baseQuery = [
            'tab' => $tab,
            'search' => $search,
            'order_by' => $orderBy,
            'order_dir' => $orderDir,
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($tab === 'phrases') {
            $data = $this->repository->phrases($search, $orderBy, $orderDir, $page, $perPage);
            $totalPages = max(1, (int) ceil($data['total'] / $perPage));

            return $this->render('@Modules/quince_search_score/views/templates/admin/phrases.html.twig', [
                'activeTab' => $tab,
                'items' => $data['items'],
                'total' => $data['total'],
                'page' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'perPageOptions' => self::PER_PAGE_OPTIONS,
                'search' => $search,
                'orderBy' => $orderBy,
                'orderDir' => $orderDir,
                'baseQuery' => $baseQuery,
            ]);
        }

        $category = (int) $request->query->get('category', 0);
        $scoreMinRaw = $request->query->get('score_min', '');
        $scoreMaxRaw = $request->query->get('score_max', '');
        $scoreMin = $scoreMinRaw !== '' && $scoreMinRaw !== null ? (int) $scoreMinRaw : null;
        $scoreMax = $scoreMaxRaw !== '' && $scoreMaxRaw !== null ? (int) $scoreMaxRaw : null;

        $baseQuery['category'] = $category;
        $baseQuery['score_min'] = $scoreMinRaw;
        $baseQuery['score_max'] = $scoreMaxRaw;

        $data = $this->repository->products(
            $idLang,
            $idShop,
            $search,
            $category,
            $scoreMin,
            $scoreMax,
            $orderBy,
            $orderDir,
            $page,
            $perPage
        );
        $totalPages = max(1, (int) ceil($data['total'] / $perPage));

        return $this->render('@Modules/quince_search_score/views/templates/admin/products.html.twig', [
            'activeTab' => $tab,
            'items' => $data['items'],
            'total' => $data['total'],
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'search' => $search,
            'category' => $category,
            'scoreMin' => $scoreMinRaw,
            'scoreMax' => $scoreMaxRaw,
            'categories' => $this->repository->categoriesFlat($idLang, $idShop),
            'orderBy' => $orderBy,
            'orderDir' => $orderDir,
            'baseQuery' => $baseQuery,
        ]);
    }

    public function updateScoreAction(Request $request): RedirectResponse
    {
        $back = $this->backTo($request, 'products');

        if (!$this->isCsrfTokenValid('quince_search_score_update_score', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $back;
        }

        $idProduct = (int) $request->request->get('id_product');
        $score = (int) $request->request->get('score');

        if ($idProduct <= 0 || !$this->repository->setScore($idProduct, $score)) {
            $this->addFlash('error', $this->trans('Could not save the score.', self::DOMAIN));

            return $back;
        }

        $this->addFlash('success', $this->trans('Score saved.', self::DOMAIN));

        return $back;
    }

    public function bulkDeleteProductsAction(Request $request): RedirectResponse
    {
        $back = $this->backTo($request, 'products');

        if (!$this->isCsrfTokenValid('quince_search_score_products_bulk_delete', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $back;
        }

        $ids = $this->idList($request->request->get('ids', ''));

        if ($ids === [] || !$this->repository->deleteProducts($ids)) {
            $this->addFlash('error', $this->trans('No rows were selected.', self::DOMAIN));

            return $back;
        }

        $this->addFlash('success', $this->trans('Selected scores removed.', self::DOMAIN));

        return $back;
    }

    public function bulkDeletePhrasesAction(Request $request): RedirectResponse
    {
        $back = $this->backTo($request, 'phrases');

        if (!$this->isCsrfTokenValid('quince_search_score_phrases_bulk_delete', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $back;
        }

        $ids = $this->idList($request->request->get('ids', ''));

        if ($ids === [] || !$this->repository->deletePhrases($ids)) {
            $this->addFlash('error', $this->trans('No rows were selected.', self::DOMAIN));

            return $back;
        }

        $this->addFlash('success', $this->trans('Selected phrases removed.', self::DOMAIN));

        return $back;
    }

    /**
     * Przekierowanie na tę samą listę, z tym samym query stringiem, z którym
     * przyszło żądanie POST — filtr/sort/strona wracają bez zmian.
     */
    private function backTo(Request $request, string $fallbackTab): RedirectResponse
    {
        $params = $request->query->all();
        if (!isset($params['tab'])) {
            $params['tab'] = $fallbackTab;
        }

        return $this->redirectToRoute('quince_search_score', $params);
    }

    /** @return int[] */
    private function idList(string $raw): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
