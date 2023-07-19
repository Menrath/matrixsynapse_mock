<?php

namespace App\Controller;

use App\Entity\Externalids;
use App\Entity\Medias;
use App\Entity\Passwords;
use App\Entity\RoomMember;
use App\Entity\Rooms;
use App\Entity\Threepids;
use App\Entity\Tokens;
use App\Entity\Users;
use App\Traits\GeneralTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/{serverID}/backoffice")
 */
class BackOfficeController extends AbstractController {

    use GeneralTrait;

    /**
     * Create admin user.
     *
     * @Route("/create-admin", name="backOfficeCreateAdmin")
     * @param string $serverID
     * @param Request $request
     * @return JsonResponse
     */
    public function backOfficeCreateAdmin(string $serverID, Request $request) : JsonResponse {
        $method = $request->getMethod();
        if ($method === 'POST') {
            $entityManager = $this->getDoctrine()->getManager();

            $user = $entityManager->getRepository(Users::class)->findOneBy(['userid' => '@admin:synapse']);
            if (!$user) {
                $user = new Users();
                $user->setServerid($serverID);
                $user->setUserid('@admin:synapse');
                $user->setDisplayname('Admin User');
                $user->setAdmin(true);
            }

            // Process tokens.
            $token = $entityManager->getRepository(Tokens::class)
                    ->findOneBy(['userid' => $user->getId()]);
            if (!$token) {
                // New user, or existing user without any associated Tokens.
                $token = new Tokens();
                $token->setAccesstoken($this->generateToken('access-token'));
                $token->setRefreshtoken($this->generateToken('refresh-token'));
                $token->setExpiresinms();
                $token->setServerid($serverID);

                $user->addtoken($token);
                $token->setUserid($user);
                $entityManager->persist($token);
            }

            // Process password.
            $passwords = $entityManager->getRepository(Passwords::class)
                    ->findOneBy(['userid' => $user->getId()]);
            if (!$passwords) {
                // 1. Generates and returns token as password.
                // 2. Generates and returns token pattern.
                $password = $this->hashPassword('password', null, true);

                // New user, or existing user without any associated Tokens.
                $passwords = new Passwords();
                $passwords->setPassword($password['token']);
                $passwords->setServerid($serverID);

                $user->addPasswords($passwords);
                $user->setPasswordpattern($password['pattern']);
                $passwords->setUserid($user);
                $entityManager->persist($passwords);
            }
            $entityManager->persist($user);
            $entityManager->flush();

            return new JsonResponse((object)[
                'user_id' => $user->getUserid(),
                'password' => 'password'
            ], 200);
        } else {
            return new JsonResponse((object)[
                'errcode' => 'M_UNRECOGNIZED',
                'error' => 'Unrecognized request'
            ], 403 );
        }
    }

    /**
     * @Route("/reset", name="backOfficeReset")
     * @param string $serverID
     * @return JsonResponse
     */
    public function backOfficeReset(string $serverID) : JsonResponse
    {
        $entities = [
            Users::class,
            Rooms::class,
            Medias::class
        ];

        $entityManager = $this->getDoctrine()->getManager();
        foreach ($entities as $entityClass) {
            $entities = $this->getDoctrine()
                ->getRepository($entityClass)
                ->findBy(['serverid' => $serverID]);
            foreach ($entities as $entity) {
                $entityManager->remove($entity);
            }
        }
        $entityManager->flush();

        return new JsonResponse((object) ['reset' => true]);
    }

    /**
     * @Route("/rooms", name="backOfficeGetAllRooms")
     * @return JsonResponse
     */
    public function getAllRooms(string $serverID): JSONResponse
    {
        $rooms = $this->getDoctrine()
            ->getRepository(Rooms::class)
            ->findBy(['serverid' => $serverID]);

        $responsedata = (object) [
            'rooms' => array_map(function ($room) {
                $roomdata = $room->jsonSerialize();
                $roomdata->members = array_map(
                    fn($membership) => $membership->getUser()->jsonSerialize(),
                    array_filter(
                        $room->getMembers()->toArray(),
                        function (RoomMember $membership): bool {
                            if ($membership->getState() !== null) {
                                return false;
                            }

                            return !$membership->getBanned() && $membership->getAccepted();
                        },
                    ),
                );
                return $roomdata;
            }, $rooms),
        ];

        return new JsonResponse($responsedata);
    }

    /**
     * @Route("/users", name="backOfficeGetAllUsers")
     * @return JsonResponse
     */
    public function getAllUsers(string $serverID): JSONResponse
    {
        $users = $this->getDoctrine()
            ->getRepository(Users::class)
            ->findBy(['serverid' => $serverID]);

        return new JsonResponse(
            (object) [
                'users' => $users,
            ],
            200
        );
    }

    /**
     * @Route("/create", methods={"PUT"})
     * @param string $serverID
     * @return JsonResponse
     */
    public function setData(
        Request $request,
        string $serverID,
    ): JsonResponse {
        $entityManager = $this->getDoctrine()->getManager();
        $payload = json_decode($request->getContent());
        $host = $request->getHost();

        $returndata = (object) [
            'users' => [],
            'rooms' => [],
        ];

        $userRepository = $entityManager->getRepository(Users::class);
        $admin = $userRepository->findOneBy(
            [
                'admin' => true,
                'serverid' => $serverID,
            ]
        );

        if (property_exists($payload, 'users')) {
            foreach ($payload->users as $userdata) {
                $user = new Users();
                $user->setServerid($serverID);
                $user->setDisplayname($userdata->fullname);
                $user->setUserid($userdata->id);
                $user->setAdmin(false);
                $entityManager->persist($user);
                $returndata->users[$user->getUserid()] = $user->jsonSerialize();
            }
        }

        if (property_exists($payload, 'rooms')) {
            foreach ($payload->rooms as $roomdata) {
                $roomName = $roomdata->name ?? rand();
                $roomID = sprintf(
                    "!%s:%s",
                    substr(
                        hash('sha256', ($serverID . $roomName . (string) time())),
                        0,
                        18,
                    ),
                    $host,
                );

                $room = new Rooms();
                $room->setRoomid($roomID);
                $room->setName($roomName);
                $room->setTopic($roomdata->topic ?? null);
                $room->setServerid($serverID);
                $room->setCreator($admin->getUserid());
                $entityManager->persist($room);
                $returnroomdata = $room->jsonSerialize();
                $returnroomdata->roomID = $roomID;
                $returnroomdata->members = [];

                if (property_exists($roomdata, 'members')) {
                    foreach ($roomdata->members as $userid) {
                        $user = $userRepository->findOneBy([
                            'serverid' => $serverID,
                            'userid' => $userid,
                        ]);
                        $roomMember = new RoomMember();
                        $roomMember->setRoom($room);
                        $roomMember->setUser($user);
                        $roomMember->setAccepted(true);
                        $roomMember->setBanned();
                        $roomMember->setServerid($serverID);
                        $entityManager->persist($roomMember);

                        $returnroomdata->members[] = $user->getUserid();
                    }
                }

                $returndata->rooms[] = $returnroomdata;
            }
        }
        $entityManager->flush();

        return new JsonResponse($returndata, 200);
    }
}
