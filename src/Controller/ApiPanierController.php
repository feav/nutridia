<?php

namespace App\Controller;

use App\Entity\Panier;
use App\Entity\Commande;
use App\Entity\Abonnement;
use App\Form\PanierType;
use App\Service\ProductService;
use App\Repository\PanierRepository;
use App\Repository\CommandeRepository;
use App\Repository\FormuleRepository;
use App\Repository\CouponRepository;
use App\Repository\AbonnementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/panier")
 */
class ApiPanierController extends AbstractController
{
    private $productService;
    private $panierRepository;
    private $commandeRepository;
    private $couponRepository;
    private $entityManager;
    private $money_unit;
    public function __construct(AbonnementRepository $abonnementRepository,FormuleRepository $formuleRepository,PanierRepository $panierRepository,CouponRepository $couponRepository,CommandeRepository $commandeRepository, ProductService $productService)
    {
        $this->money_unit = "$";
        $this->productService = $productService;
        $this->panierRepository = $panierRepository;
        $this->commandeRepository = $commandeRepository;
        $this->abonnementRepository = $abonnementRepository;
        $this->couponRepository = $couponRepository;
        $this->formuleRepository = $formuleRepository;
        
    }
    public function getCurrentCard(): Response
    {
        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        if($user){

            $paniers = $this->panierRepository->findBy(array('user' =>  $user->getId(), 'status'=>0 ));
            $produis = array();
            $coupons = array();
            $formules = array();
            $livraison = 0;
            $total = 0;

            /**
            ** if commande do not exist create one
            **/
            if( count($paniers) ==0){
               
            }else{
                $panier = $paniers[0];
                $commandes = $panier->getCommandes();
                foreach ($commandes as $key => $commande) {
                    if($commande->getQuantity()>0)
                        $produis[] = array(
                            'name' => $commande->getProduct()->getName(),
                            'price' => $commande->getPrice(),
                            'quantity' => $commande->getQuantity(),
                            'id_product' => $commande->getProduct()->getId(),
                            'oldprice' => $commande->getQuantity(),
                            'img' => $commande->getProduct()->getImage(),
                        );
                }

                $formules_ = $panier->getAbonnements();
                foreach ($formules_ as $key => $formule) {
                    $formules[] = array(
                        'name' => $formule->getFormule()->getName(),
                        'price' => $formule->getFormule()->getPrice(),
                        'id' => $formule->getFormule()->getId(),
                        'month' => $formule->getFormule()->getMonth(),
                    );
                }
                $coupons_ = $panier->getCoupons();
                foreach ($coupons_ as $key => $coupon) {
                    $coupons[] = array(
                        'name' => $coupon->getNom(),
                        'value' => $coupon->getPriceReduction(),
                        'id' => $coupon->getId(),
                        'code' => $coupon->getCode(),
                        'type' => $coupon->getTypeReduction()
                    );
                }

                $livraison = $panier->getPriceShipping();
                $total = $panier->getTotalPrice();
            }
            return new Response( json_encode(
                array(
                    'status' => 200, 
                    'message' => "reccuperer le panier de l'utilisateur connecte", 
                    'panier'=> array(
                        'products'=>$produis,
                        'coupons' => $coupons,
                        'formules' => $formules,
                        'livraison' => $livraison,
                        'total' => $total,
                        'livraison' => $livraison

                    )
                )
            ) );

        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );
    }

    public function addItemToCard(): Response
    {

       $type = $_GET['type'];
       

        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        if($user){

            $paniers = $this->panierRepository->findBy(array('user' =>  $user->getId(), 'status'=>0 ));
            $panier = null;
            /**
            ** if commande do not exist create one
            **/
            if( count($paniers) ==0){
                $panier = new Panier();
                $panier->setUser($user);
                if ($panier) {
                    $this->entityManager->persist($panier);
                    $this->entityManager->flush();
                    $panier->initToken();
                }
            }else{
                $panier = $paniers[0];
            }
            $message = "Auncun produit importe";
            /**
            ** update product into card
            **/
            if($type == 'product'){
                $product = $_GET['product']; 
                $quantity = $_GET['quantity'];

                $product = $this->productService->findById($product);
                if($product){

                    $commande = $this->commandeRepository->findOneBy(array('product' =>  $product, 'panier'=>$panier ));
                    if($commande){
                            $message = "La quantite du produit a ete mise a jour";
                            if($quantity == $commande->getQuantity())
                                $message = "La quantite du produit est la meme";
                            $commande->setQuantity($quantity);
                    }else{
                            $commande = new Commande();
                            $commande->setPanier($panier);
                            $commande->setProduct($product);
                            $commande->setPrice($product->getPrice());
                            $commande->setQuantity($quantity);
                            $panier->addCommande( $commande );
                            $message = "Le produit a ete a ajoute a votre panier";
                    }

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            if($type == 'coupon'){
                $coupon_code = $_GET['product']; 
                $coupon = $this->couponRepository->findOneByCode($coupon_code);
                if($coupon){

                    $exist = $panier->getCoupons()->contains($coupon);
                    if($coupon->getCurrentUsage() < $coupon->getCurrentUsage() ){
                        if($exist){
                            $message = "Le coupon est deja dans votre panier";
                        }else{
                            $panier->addCoupon($coupon);
                            $message = "Le coupon a ete a ajoute a votre panier";
                            $coupon->setCurrentUsage( $coupon->getCurrentUsage() + 1);
                            $this->entityManager->persist($coupon);
                            $this->entityManager->flush();
                        }
                    }else{
                        return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon a atteint son nombre maximal d'utilisation" )) );
                    }
                    
                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon n'existe pas dans notre boutique" )) );
                }

            }
            /**
            ** update formule into card
            **/
            if($type == 'formule'){
                $formule_id = $_GET['product']; 

                $formule = $this->formuleRepository->findOneById($formule_id);
                if($formule){

                    $abonnement = $this->abonnementRepository->findOneBy(array('formule' =>  $formule, 'panier'=>$panier ));
                    if($abonnement){
                        $message = "La formule est deja dans votre panier";
                    }else{
                        $date = date("d/m/Y h:i:s");
                        $date = strtotime(date("d/m/Y h:i:s", strtotime($date)) . "+2 months");
                        $date_end = date("d/m/Y h:i:s",$date);

                        $abonnement = new Abonnement();
                        $abonnement->setPanier($panier);
                        $abonnement->setFormule($formule);
                        $abonnement->setStart($date);
                        $abonnement->setEnd($date_end);
                        $abonnement->setState(0);

                        $this->entityManager->persist($abonnement);
                        $this->entityManager->flush();

                        $message = "La formule a ete a ajoutee a votre panier";
                    }

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            $this->entityManager->persist($panier);
            $this->entityManager->flush();

            return new Response( json_encode(array('status' => 200, 'message' => $message )) );

        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );
    }
    /**
     * @Route("/", name="panier_index", methods={"GET"})
     */
    public function index(PanierRepository $panierRepository): Response
    {

        $user = $this->getUser();
        if( false){
            var_dump($user->getId());   
            $paniers = $panierRepository->find(array('user' =>  $user->getId(), 'status'=>0 ));
            if(count($panier)){
                 $panier = new Panier();

                if ($panier) {
                    $entityManager = $this->getDoctrine()->getManager();
                    $entityManager->persist($panier);
                    $entityManager->flush();
                    $panier->initToken();
                    $entityManager->persist($panier);
                    $entityManager->flush();
                }
            }
        }
        die();
        // return $this->render('panier/index.html.twig', [
        //     'paniers' => $panierRepository->findAll(),
        // ]);
    }
}