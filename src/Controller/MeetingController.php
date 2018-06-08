<?php


declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\Annotations as FOSRest;
use App\Entity\Meeting;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use App\Event\MeetingRegisteredEvent;
use App\MeetingEvents;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use App\Services\Interfaces\MeetingInterface;
use FOS\RestBundle\Controller\Annotations\RequestParam;
use Swagger\Annotations as SWG;
use Nelmio\ApiDocBundle\Annotation\Model;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Meeting Controller
 *
 * @Route("/api/v1")
 */

class MeetingController extends Controller {

    /**
     * Create Meeting.
	 * @FOSRest\Post("/meeting")
	 * @SWG\Response(
	 *     response=200,
	 *     description="Json hashmap with all user meta data",
	 *     @SWG\Schema(
	 *        type="object",
	 *        example={"foo": "bar", "hello": "world"}
	 *     )
	 *
	 *)
     * @return array
     */
    public function postMeetingAction(Request $request, EventDispatcherInterface $dispatcher,
        ValidatorInterface $validator, AdapterInterface $cache, MessageBusInterface $bus)
    {
        $postdata = json_decode($request->getContent());
        $meeting = new Meeting();
        $meeting->setName($postdata->name);
        $meeting->setDescription($postdata->description);
        $meeting->setDateTime(new \DateTime($postdata->date));

        $errors = $validator->validate($meeting);

        if (count($errors) > 0) {

            return View::create(array('errors' => $errors), Response::HTTP_BAD_REQUEST);
        }

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository(User::class)->find($postdata->userid);
        $meeting->setUser($user);
        $em->persist($meeting);
        $em->flush();

        $meetingEvent = new MeetingRegisteredEvent($meeting);
        $dispatcher->dispatch(MeetingEvents::MEETING_REGISTERED, $meetingEvent);

        $response = array(
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'description' => $meeting->getDescription(),
            'date' => $meeting->getDateTime()
        );
        return View::create($response, Response::HTTP_CREATED , []);
    }

    /**
     * Lists all Meetings.
     * @FOSRest\Get("/meeting")
     *
     * @QueryParam(name="search", requirements="[a-z]+", description="search", allowBlank=false)
     * @QueryParam(name="page", requirements="\d+", default="1", description="Page of the overview.")
     * @QueryParam(name="limit", requirements="\d+", default="5", description="How many notes to return.")
     * @QueryParam(name="sort", requirements="(asc|desc)", allowBlank=false, default="desc", description="Sort direction")
     *
     * @return array
     */
    public function getMeetingsAction(MeetingInterface $meetingService, ParamFetcherInterface $paramFetcher,AdapterInterface $cache)
    {

        $repository = $this->getDoctrine()->getRepository(Meeting::class);
        // add pagination on data using ParamFetcherInterface
        $limit = $paramFetcher->get('limit');
        $page = $limit * ($paramFetcher->get('page') - 1);
        $cacheKey = 'meetings';
        $cachedItem = $this->get('cache.app')->getItem($cacheKey);
        if(false === $cachedItem->isHit()) {
            $meetings = $repository->findBy(array(), array('id' => $paramFetcher->get('sort')), $limit, $page);
            $cachedItem->set($cacheKey, $meetings);
            $cache->save($cachedItem);
        }

        // Move this in Meeting normalizer
        $response = array();
        foreach($cachedItem as $meeting) {
            // find users
            $users = [];
            foreach($meeting->getUsers() as $user) {
                $users[] = array(
                    'id' => $user->getId(),
                    'fullname' => $user->getFullName(),
                    'email' => $user->getEmail()
                );
            }

            $response[] = array(
                'id' => $meeting->getId(),
                'name' => $meeting->getName(),
                'description' => $meeting->getDescription(),
                'date' => $meeting->getDateTime(),
                'users' => $users
            );
        }
        return View::create(array(
            "metadata" => array("limit" => $limit, "start"=> $page),
            'collections' => $response
        ), Response::HTTP_OK , []);
    }

    /**
     * Get Meeting.
     * @FOSRest\Get(path = "/meeting/{id}")
     *
     * @return array
     */
    public function getMeetingAction($id)
    {
        $repository = $this->getDoctrine()->getRepository(Meeting::class);

        // query for a single Product by its primary key (usually "id")
        $meeting = $repository->find($id);
        if(!$meeting) {
            throw new HttpException(404, 'Meeting not found');
        }
        // Move this in Meeting normalizer
        $response = array(
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'description' => $meeting->getDescription(),
            'date' => $meeting->getDateTime(),
            'users' => [],
            'tags' => []
        );
        $users = $meeting->getUsers();
        if($users) {
            foreach($users as $user) {
                $response['users'][] = array(
                    'id' => $user->getId(),
                    'fullname' => $user->getFullName(),
                    'email' => $user->getEmail(),
                );
            }
        }

        $tags = $meeting->getTags();
        if($tags) {
            foreach($tags as $tag) {
                $response['tags'][] = $tag->getName();
            }
        }

        return View::create($response, Response::HTTP_OK , []);
    }

    /**
     * Update an Meeting.
     * @FOSRest\Put(path = "/meeting/{id}")
     *
     * @return array
     */
    public function putMeetingAction($id, Request $request) {
        $em = $this->getDoctrine()->getManager();
        $meeting = $em->getRepository(Meeting::class)->find($id);
        if(!$meeting) {
            throw new HttpException(404, 'Meeting not found');
        }
        $postdata = json_decode($request->getContent());
        $meeting->setName($postdata->name);
        $meeting->setDescription($postdata->description);
        $meeting->setDateTime(new \DateTime($postdata->date));
        $em->persist($meeting);
        $em->flush();
        return View::create($meeting, Response::HTTP_OK , []);
    }

    /**
     * Delete an Meeting.
     *
     * @FOSRest\Delete(path = "/meeting")
     *
     * @return array
     */
    public function deleteAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $meeting = $em->getRepository(Meeting::class)->find($request->get('meeting_id'));
        if(!$meeting) {
            throw new HttpException(404, 'Meeting not found');
        }
        $em->remove($meeting);
        $em->flush();
        return View::create(null, Response::HTTP_NO_CONTENT);
    }

}
