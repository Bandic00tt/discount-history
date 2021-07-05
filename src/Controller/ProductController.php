<?php
namespace App\Controller;

use App\Dto\Location;
use App\Entity\Product;
use App\Repository\DiscountHistoryRepository;
use App\Repository\DiscountRepository;
use App\Repository\ProductRepository;
use App\Service\DateHelper;
use App\Service\DataHandler;
use App\Service\ProductList;
use App\ValueObject\Cities;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProductController extends AbstractController
{
    /**
     * @throws Exception
     */
    public function __construct(
        private EntityManagerInterface $em,
        private DateHelper $dateHelper,
        private Location $location,
        private ProductRepository $productRepository,
        private DiscountHistoryRepository $discountHistoryRepository,
        private DiscountRepository $discountRepository
    )
    {
        $this->location = $this->getLocation();
    }

    /**
     * @Route ("/", name="app_index")
     * @return RedirectResponse
     */
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_list', [
            'cityEn' => $this->location->cityNameEn
        ]);
    }

    /**
     * @Route (
     *     "/{cityEn}/{page}",
     *     defaults={"page"=1},
     *     requirements={"cityEn"="\w+", "page"="\d+"},
     *     name="app_list"
     * )
     * @param Request $request
     * @return Response
     * @throws NonUniqueResultException
     * @throws NoResultException
     * @throws Exception
     */
    public function list(Request $request): Response
    {
        $cityEn = $request->get('cityEn');
        $page = (int) $request->get('page', 1);
        $searchQuery = $request->get('q');

        // Список товаров для вывода на одной странице
        $products = $this->productRepository
            ->findListByParams($this->location->cityId, $page, $searchQuery);
        // История скидок по списку товаров
        $discountHistory = $this->discountHistoryRepository
            ->findAllByLocationIdAndProducts($this->location->cityId, $products);
        // Всего товаров
        $totalProducts = $this->productRepository
            ->findTotalByLocationIdAndSearchQuery($this->location->cityId, $searchQuery);

        $productList = new ProductList($this->dateHelper, $products, $discountHistory);
        // Параметры списка товаров
        $productListViewParams = $productList->getProductListViewParams($this->discountRepository);
        // Параметры пагинации
        $productPaginationViewParams = $productList->getProductPaginationViewParams($page, $totalProducts);

        return $this->render('/product/list.html.twig', [
            'products' => $products,
            'params' => ['cityEn' => $cityEn, 'page' => $page],
            'location' => $this->location,
            'year' => $productListViewParams->year,
            'yearDates' => $productListViewParams->yearDates,
            'discountDates' => $productListViewParams->discountDates,
            'discountYears' => $productListViewParams->discountYears,
            'activeProductDiscounts' => $productListViewParams->activeProductDiscounts,
            'currentPage' => $page,
            'firstPage' => $productPaginationViewParams->firstPage,
            'lastPage' => $productPaginationViewParams->lastPage,
            'totalPages' => $productPaginationViewParams->totalPages,
        ]);
    }

    /**
     * todo: it seems too complex, needs refactoring
     * @Route ("/{cityEn}/product/{id}", name="app_product", methods={"GET"})
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function product(Request $request): Response
    {
        $productId = $request->get('id');

        $product = $this->em
            ->getRepository(Product::class)
            ->findOneBy(['product_id' => $productId]);
        $activeProductDiscounts = $this->discountRepository->findActiveProductDiscounts([$product]);
        $discountHistory = $this->discountHistoryRepository->findAllByLocationIdAndProducts($this->location->cityId, [$product]);
        $productDiscountYears = $this->dateHelper->getDiscountYears($discountHistory)[$productId] ?? [];
        $datesByYears = [];
        $productDiscountDatesByYears = [];
        foreach ($productDiscountYears as $year) {
            $productDiscountDatesByYears[$year] = $this->dateHelper->getDiscountDates($year, $discountHistory)[$productId];
            $datesByYears[$year] = $this->dateHelper->getYearDates($year);
        }

        return $this->render('/product/product.html.twig', [
            'product' => $product,
            'activeProductDiscounts' => $activeProductDiscounts,
            'productDiscountYears' => $productDiscountYears,
            'datesByYears' => $datesByYears,
            'productDiscountDatesByYears' => $productDiscountDatesByYears,
        ]);
    }

    /**
     * @Route ("/time-limited-discount-data", name="app_time_limited_discount_data", priority="1")
     * @param Request $request
     * @return JsonResponse
     * @throws NonUniqueResultException
     */
    public function timeLimitedDiscountData(Request $request): JsonResponse
    {
        $productId = $request->get('productId');
        $discountDate = $request->get('discountDate');

        $discountHistory = $this->discountHistoryRepository->findByProductIdAndTimeLimit($productId, $discountDate);

        $view = $this->renderView('/product/_partials/productCard.html.twig', [
            'priceDiscount' => $discountHistory->getPriceDiscount(),
            'priceNormal' => $discountHistory->getPriceNormal(),
            'dateBegin' => date('d.m.Y', $discountHistory->getDateBegin()),
            'dateEnd' => date('d.m.Y', $discountHistory->getDateEnd()),
        ]);

        return $this->json(['html' => $view]);
    }

    /**
     * @Route ("/discount-data-by-year", name="app_discount_data_by_year", priority="1")
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function discountDataByYear(Request $request): JsonResponse
    {
        $productId = $request->get('productId');
        $year = $request->get('year');

        $yearDates = $this->dateHelper->getYearDates($year);
        $product = $this->em
            ->getRepository(Product::class)
            ->findOneBy(['product_id' => $productId]);

        $discountHistory = $this->discountHistoryRepository->findAllByLocationIdAndProducts($this->location->cityId, [$product]);
        $productDiscountDates = $this->dateHelper->getDiscountDates($year, $discountHistory)[$productId];
        $productDiscountYears = $this->dateHelper->getDiscountYears($discountHistory)[$productId];

        $view = $this->renderView('/product/_partials/history.html.twig', [
            'year' => $year,
            'yearDates' => $yearDates,
            'product' => $product,
            'productDiscountDates' => $productDiscountDates,
            'productDiscountYears' => $productDiscountYears,
        ]);

        return $this->json(['html' => $view]);
    }

    /**
     *
     * todo: transfer query
     * @Route ("/products", name="app_products", priority="1")
     * @param Request $request
     * @return Response
     */
    public function products(Request $request): Response
    {
        $q = $request->get('q');

        $query = $this->em
            ->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');

        if ($q) {
            $query->where('p.name like :q')
                ->setParameter('q', '%'. $q .'%');
        }

        $products = $query->getQuery()->getResult();

        return $this->render('/product/products.html.twig', [
            'products' => $products
        ]);
    }

    /**
     * @return Location
     * @throws Exception
     */
    private function getLocation(): Location
    {
        $cities = Cities::list();
        $locationId = (int) ($_COOKIE['discountLocationId'] ?? DataHandler::MOSCOW_ID);

        $cityItem = $cities[$locationId] ?? null;

        if ($cityItem) {
            $location = new Location();
            $location->cityId = $locationId;
            $location->cityNameRu = $cityItem['ru'];
            $location->cityNameEn = $cityItem['en'];

            return $location;
        }

        throw new Exception('City item not found');
    }
}