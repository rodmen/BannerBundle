<?php
namespace Acilia\Bundle\BannerBundle\Service;

use Acilia\Bundle\BannerBundle\Library\BannerTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\Bundle\DoctrineBundle\Registry as Doctrine;

class BannerService
{
    /**
     * Memcache Service
     * @var \Acilia\Component\Memcached\Service\MemcachedService
     */
    protected $memcache;

    /**
     * Request Stack
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    protected $requestStack;

    /**
     * Doctrine
     */
    protected $doctrine;

    protected $types;

    // Options
    protected $place;
    protected $referenceId;

    public function __construct(RequestStack $requestStack, Doctrine $doctrine, MemcachedService $memcache)
    {
        $this->requestStack = $requestStack;
        $this->doctrine = $doctrine;
        $this->memcache = $memcache;

        $this->place = BannerTag::PLACE_ROS;
        $this->referenceId = null;
    }

    public function getCode($regionId, $regionCC, $bannerType, $place = null, $referenceId = null)
    {
        if ($place == null) {
            $place = $this->place;
        }
        if ($referenceId === false) {
            $referenceId = null;
        } elseif ($referenceId == null) {
            $referenceId = $this->referenceId;
        }

        // Get URL
        $currentUrl = $this->requestStack->getMasterRequest()->getPathInfo();

        $key = 'Banner:' . $regionCC . ':' . $place . ':' . $bannerType . ':' . $referenceId . ':' . sha1($currentUrl);
        $bannerTag = $this->memcache->get($key);
        if ($this->memcache->notFound()) {
            // Create Banner Tag
            $bannerTag = new BannerTag();
            $bannerTag->setCountryCode($regionCC)
                ->setRegionId($regionId)
                ->setBannerType($bannerType)
                ->setPlace($place)
                ->setReferenceId($referenceId);

            if ($this->isPageAvailable($bannerTag, $currentUrl)) {
                return '<!-- BANNER BEGIN - This page has it\'s Ads Disabled - BANNER END -->';
            }

            // Fill Banner Tag
            $this->fillBannerTag($bannerTag, $currentUrl);

            // Fallback
            // If banner tag is empty, type is not common, and place is serie, and there was a reference id, look for a serie banner without reference id
            if ($bannerTag->isEmpty() && $bannerTag->getBannerType() != BannerTag::TYPE_COMMON && $place == BannerTag::PLACE_SERIE && $referenceId !== null) {
                $bannerTag->setPlace(BannerTag::PLACE_SERIE)
                    ->setReferenceId(null);
                $this->fillBannerTag($bannerTag, $currentUrl);
            }

            // Fallback
            // If banner tag is empty, type is not common, and place is movie, and there was a reference id, look for a movie banner without reference id
            if ($bannerTag->isEmpty() && $bannerTag->getBannerType() != BannerTag::TYPE_COMMON && $place == BannerTag::PLACE_MOVIE && $referenceId !== null) {
                $bannerTag->setPlace(BannerTag::PLACE_MOVIE)
                    ->setReferenceId(null);
                $this->fillBannerTag($bannerTag, $currentUrl);
            }

            // Fallback
            // If banner tag is empty, type is common, and place is serie, and there was a reference id, look for a serie banner without reference id
            if ($bannerTag->isEmpty() && $bannerTag->getBannerType() == BannerTag::TYPE_COMMON && $place == BannerTag::PLACE_SERIE && $referenceId !== null) {
                $bannerTag->setPlace(BannerTag::PLACE_SERIE)
                    ->setReferenceId(null);
                $this->fillBannerTag($bannerTag, $currentUrl);

                // Fallback
                // If banner tag is still empty, look for a ROS tag
                if ($bannerTag->isEmpty()) {
                    $bannerTag->setPlace(BannerTag::PLACE_ROS)
                        ->setReferenceId(null);
                    $this->fillBannerTag($bannerTag, $currentUrl);
                }
            }

            // Fallback
            // If banner tag is empty, type is common, and place is movie, and there was a reference id, look for a movie banner without reference id
            if ($bannerTag->isEmpty() && $bannerTag->getBannerType() == BannerTag::TYPE_COMMON && $place == BannerTag::PLACE_MOVIE && $referenceId !== null) {
                $bannerTag->setPlace(BannerTag::PLACE_MOVIE)
                    ->setReferenceId(null);
                $this->fillBannerTag($bannerTag, $currentUrl);

                // Fallback
                // If banner tag is still empty, look for a ROS tag
                if ($bannerTag->isEmpty()) {
                    $bannerTag->setPlace(BannerTag::PLACE_ROS)
                        ->setReferenceId(null);
                    $this->fillBannerTag($bannerTag, $currentUrl);
                }
            }

            // Fallback
            // If banner tag is empty, type is not common, and place is not ROS or Home, look up for a ROS banner
            if ($bannerTag->isEmpty() && $bannerTag->getBannerType() != BannerTag::TYPE_COMMON && $place != BannerTag::PLACE_ROS) {
                $bannerTag->setPlace(BannerTag::PLACE_ROS)
                    ->setReferenceId(null);
                $this->fillBannerTag($bannerTag, $currentUrl);
            }

            // Save on Memcache
            $this->memcache->set($key, $bannerTag, 60);
        }

        return $bannerTag;
    }

    public function getType($slug)
    {
        $key = 'Banner:Types';

        if (!is_array($this->types)) {
            $types = $this->memcache->get($key);
            if ($this->memcache->notFound()) {
                $bannerTypes = $this->doctrine->getManager()->getRepository('BannerBundle:BannerType')->findAll();
                $types = array();

                foreach ($bannerTypes as $bannerType) {
                    $types[$bannerType->getSlug()] = $bannerType->getId();
                }

                $this->memcache->set($key, $types, 1440);
            }

            $this->types = $types;
        }

        if (isset($this->types[$slug])) {
            return $this->types[$slug];
        } elseif (isset($this->types['none'])) {
            return $this->types['none'];
        } else {
            return 0;
        }
    }

    public function configure(array $options)
    {
        if (isset($options['place'])) {
            $this->place = $options['place'];
        }

        if (isset($options['referenceId'])) {
            $this->referenceId = $options['referenceId'];
        }
    }

    protected function compareUrl($currentUrl, $pattern)
    {
        $check = false;

        if ($pattern != '') {
            $_includes = explode(PHP_EOL, $pattern);
            foreach ($_includes as $_include) {
                $_include = '@^' . trim(str_replace('*', '.*', $_include)) . '$@i';
                if (preg_match($_include, $currentUrl)) {
                    $check = true;
                }
            }
        }

        return $check;
    }

    protected function fillBannerTag(BannerTag $bannerTag, $currentUrl)
    {
        // Fetch Banners
        $dql = 'SELECT b '
            . 'FROM BannerBundle:Banner b '
            . 'WHERE b.status = true '
            . '  AND b.region = :regionId '
            . '  AND b.type = :typeId '
            . '  AND b.place = :place '
            . '  AND b.publishSince <= :publishSince '
            . '  AND (b.publishUntil >= :publishUntil OR b.publishUntil IS NULL OR b.publishUntil = \'0000-00-00\') '
            . ($bannerTag->getReferenceId() !== null ? '  AND b.referenceId = :referenceId ' : '  AND b.referenceId IS NULL ')
            . 'ORDER BY b.modifiedAt DESC ';

        $query = $this->doctrine->getManager()->createQuery($dql)
            ->setParameter('regionId', $bannerTag->getRegionId())
            ->setParameter('typeId', $this->getType($bannerTag->getBannerType()))
            ->setParameter('place', $bannerTag->getPlace())
            ->setParameter('publishSince', date('Y-m-d'))
            ->setParameter('publishUntil', date('Y-m-d'));

        if ($bannerTag->getReferenceId() !== null) {
            $query->setParameter('referenceId', $bannerTag->getReferenceId());
        }

        // Iterate Banners
        $banners = $query->getResult();
        foreach ($banners as $banner) {
            if (trim($banner->getUrlInclude()) == '' && trim($banner->getUrlExclude()) == '' && $bannerTag->isEmpty()) {
                $bannerTag->setTag($banner->getTag())
                    ->setId($banner->getId())
                    ->setName($banner->getName());
            } else {
                // Check if URL is in the Includes
                if ($this->compareUrl($currentUrl, $banner->getUrlInclude())) {
                    $bannerTag->setTag($banner->getTag())
                        ->setId($banner->getId())
                        ->setName($banner->getName());
                    break;
                }

                // Check if URL is in the Excludes
                if ($this->compareUrl($currentUrl, $banner->getUrlExclude())) {
                    $bannerTag->clear();
                }
            }
        }

        // For Debugging
        $bannerTag->addDebug("Region Id: {$bannerTag->getRegionId()} | Country Code: {$bannerTag->getCountryCode()}  | Place: {$bannerTag->getPlace()} " . (($bannerTag->getReferenceId() !== null) ? "| ReferenceId: {$bannerTag->getReferenceId()} " : ''). "| Type: {$bannerTag->getBannerType()}");
    }

    protected function isPageAvailable(BannerTag $bannerTag, $currentUrl)
    {
        // Fetch Disabling Banners
        $dql = 'SELECT b '
            . 'FROM BannerBundle:Banner b '
            . 'WHERE b.status = true '
            . '  AND b.region = :regionId '
            . '  AND b.type = :typeId '
            . '  AND b.publishSince <= :publishSince '
            . '  AND (b.publishUntil >= :publishUntil OR b.publishUntil IS NULL OR b.publishUntil = \'0000-00-00\') '
            . 'ORDER BY b.modifiedAt DESC ';

        $query = $this->doctrine->getManager()->createQuery($dql)
            ->setParameter('regionId', $bannerTag->getRegionId())
            ->setParameter('typeId', $this->getType('none'))
            ->setParameter('publishSince', date('Y-m-d'))
            ->setParameter('publishUntil', date('Y-m-d'));

        // Iterate Banners
        $banners = $query->getResult();
        foreach ($banners as $banner) {
            if ($this->compareUrl($currentUrl, $banner->getUrlInclude())) {
                return true;
            }
        }

        return false;
    }
}