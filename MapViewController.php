<?php

namespace TruckHub\Controllers\Admin;

use Exception;
use TruckHub\Controllers\Controller;
use TruckHub\Classes\Tools;
use TruckHub\Classes\Itinerary;
use TruckHub\Classes\Dispatch;
use TruckHub\Classes\PCMiler\AlkMaps;
use TruckHub\Classes\Status\LegStatus;
use TruckHub\Classes\MapView\Search\Factory\SearchFactory;
use TruckHub\Classes\MapView\MapViewStaticFactory;
use TruckHub\Classes\MapView\Filters;
use TruckHub\Classes\MapView\Notifications;
use TruckHub\Classes\MapView\DriverSidePanel;
use TruckHub\Models\Legs;
use TruckHub\Models\Jobs;
use TruckHub\Models\ltl;
use TruckHub\Models\Drivers;
use TruckHub\Models\AssignmentHistory;
use TruckHub\Models\Itineraries;
use TruckHub\Models\Metrics;
use TruckHub\Models\Beacons;
use Respect\Validation\Validator as v;
use Illuminate\Database\Capsule\Manager as DB;

use TruckHub\Models\RoutineItinerary;
use TruckHub\Models\RoutineLegs;
use TruckHub\Models\Origins;
use TruckHub\Models\Destinations;
use TruckHub\Models\Routines;

use TruckHub\Models\JobsLegsOrder;

use Dompdf\Dompdf;


class MapViewController extends Controller
{
    protected $department;

    public function getMapViewDefault($request, $response, $arguments)
    {
        $this->setDepartmentContext();
        return $response->withRedirect($this->container->router->pathFor(
            'admin.map-view',
            [
                'department' => $this->twig_data['department_context']
            ]
        ));
    }

    public function getMapView($request, $response, $arguments)
    {
        $map_view = MapViewStaticFactory::create($arguments['department']);
        foreach ($map_view->jobs as $job) {
            $job->current_leg = new \stdClass;
            $job->current_leg->id = $this->container->truckhub->job->getCurrentLegId($job->id);
            $job->current_leg->target = $this->container->truckhub->status->leg->isLegStatusOrigin(Legs::select('status')->find($job->current_leg->id)->status) ? 'origin' : 'destination';
        }
        $this->twig_data['map_view'] = $map_view;
        $this->twig_data['jobs'] = $map_view->jobs;
        $this->twig_data['department_context'] = $arguments['department'];
        $this->twig_data['notifications'] = new Notifications($arguments['department']);
        $this->setDepartmentContext($arguments['department']);

        return $this->container->view->render($response, 'admin/map-view/map-view.twig', $this->twig_data);
    }

    public function postMapView($request, $response, $arguments)
    {
        $_SESSION['map_view']['filters']['from_date'] = $_POST['from_date'];
        $_SESSION['map_view']['filters']['to_date'] = $_POST['to_date'];
        $_SESSION['map_view']['filters']['shippers_id'] = $_POST['shippers_id'];

        if (isset($_POST['job_statuses'])) {
            $_SESSION['map_view']['filters']['job_statuses'] = array_keys($_POST['job_statuses']);
        } else {
            $_SESSION['map_view']['filters']['job_statuses'] = [];
        }

        if (isset($_POST['leg_statuses'])) {
            $_SESSION['map_view']['filters']['leg_statuses'] = array_keys($_POST['leg_statuses']);
        } else {
            $_SESSION['map_view']['filters']['leg_statuses'] = [];
        }

        $_SESSION['map_view']['filters']['map_options'] = [
            'containers' => $request->getParam('map_options')['containers'] == 'on' ? true : false,
            'truck_clusters' => $request->getParam('map_options')['truck_clusters'] == 'on' ? true : false,
            'origin_or_destination' => $request->getParam('map_options')['origin_or_destination'] == 'on' ? true : false,
            'origin_and_destination' => $request->getParam('map_options')['origin_and_destination'] == 'on' ? true : false,
            'flight_path' => $request->getParam('map_options')['flight_path'] == 'on' ? true : false,
            'traffic_layer' => $request->getParam('map_options')['traffic_layer'] == 'on' ? true : false,
            'jobs_compressed' => $request->getParam('map_options')['jobs_compressed'] == 'on' ? true : false
        ];

        if ($_SESSION['map_view']['filters']['map_options']['origin_or_destination'] &&
            $_SESSION['map_view']['filters']['map_options']['origin_and_destination']) {
            $_SESSION['map_view']['filters']['map_options']['origin_or_destination'] = false;
        } elseif (!$_SESSION['map_view']['filters']['map_options']['origin_or_destination'] &&
            !$_SESSION['map_view']['filters']['map_options']['origin_and_destination']) {
            $_SESSION['map_view']['filters']['map_options']['origin_or_destination'] = true;
        }

        return $response->withRedirect($this->container->router->pathFor(
            'admin.map-view',
            [
                'department' => $arguments['department']
            ]
        ));
    }

    public function postSearchJobs($request, $response)
    {
        $search = SearchFactory::createSearch($request->getParam('department'));
        $results = $search->find($request->getParam('search'));

        $this->twig_data['department_context'] = $request->getParam('department');
        $this->twig_data['jobs'] = $results->getJobs();

        foreach ($this->twig_data['jobs'] as &$job) {
            $job['current_leg'] = new \stdClass;
            $job['current_leg']->id = $this->container->truckhub->job->getCurrentLegId($job['id']);
            $job['current_leg']->target = $this->container->truckhub->status->leg->isLegStatusOrigin(Legs::select('status')->find($job['current_leg']->id)->status) ? 'origin' : 'destination';
        }

        return $this->container->view->render($response, 'admin/map-view/components/jobs-list-ajax.twig', $this->twig_data);
    }

    public function postDriverSidePanel($request, $response)
    {
        $this->twig_data['driver'] = $this->container->truckhub->driver->factory->createDriver(
            (int)$request->getParam('drivers_id')
        );
        if ($this->twig_data['driver']->getDepartment() == 'truckloads' ||
            $this->twig_data['driver']->getDepartment() == 'containers') {
            $this->twig_data['itinerary'] = $this->container
                ->truckhub
                ->itinerary
                ->factory
                ->createItinerary(
                    $this->container->truckhub->driver->getCurrentItineraryIdByDriverId(
                        $this->twig_data['driver']->getId()
                    )
                );
            return $this->container->view->render(
                $response,
                'admin/map-view/driver-side-panel/driver-side-panel.twig',
                $this->twig_data
            );
        }

        $driverSidePanel = new DriverSidePanel($request->getParam('drivers_id'));
        $this->twig_data = $driverSidePanel->getData('DESC');


        if (!$this->twig_data['driver']->itinerary) {
            $itinerary = new Itineraries();
            $itinerary->drivers_id = $this->twig_data['driver']->id;
            $itinerary->save();
            if ($this->twig_data['driver']->beacon->leg) {
                $this->twig_data['driver']->beacon->leg->itineraries_id = $itinerary->id;
                $this->twig_data['driver']->beacon->leg->position = 0;
                $this->twig_data['driver']->beacon->leg->save();
            }
            $driverSidePanel->getDriverInfo();
            $this->twig_data = $driverSidePanel->data;

        } else {
            if (count($this->twig_data['driver']->itinerary->legs) === 0) {
                $itineraries = Itineraries::where('drivers_id', $this->twig_data['driver']->id)
                    ->count();
                if ($itineraries == 1) {
                    $position = 0;
                    $legs = Legs::where('drivers_id', $this->twig_data['driver']->id)
                        ->orderBy('id', 'DESC')
                        ->limit(10)
                        ->get();
                    for ($i = count($legs) - 1; $i >= 0; $i--) {
                        $legs[$i]->position = $position++;
                        $legs[$i]->itineraries_id = $this->twig_data['driver']->itinerary->id;
                        $legs[$i]->save();
                    }
                }
            } else {
                // dump($this->getRoutines($this->twig_data['driver']->id));die;
                $this->twig_data['driver']['routines_list'] = $this->getRoutines($this->twig_data['driver']->id);

                // $this->twig_data['driver']->itinerary->legs = $this->removeRoutineLegs($this->twig_data['driver']->itinerary->legs);
                //  $this->twig_data['driver']['jobs_legs_order'] = $this->getAllJobsOrdered($this->twig_data['driver']->id, $this->twig_data['driver']['routines'], $this->twig_data['driver']->itinerary->legs, $this->twig_data['driver']->previous_itinerary->legs);

            }
        }


        if ($this->twig_data['driver']->type == 'truckloads' || $this->twig_data['driver']->type == 'containers') {
            return $this->container->view->render(
                $response,
                'admin/map-view/driver-side-panel/driver-side-panel.twig',
                $this->twig_data
            );
        }


        return $this->container->view->render($response, 'admin/map-view/components/driver-side-panel.twig', $this->twig_data);
    }


    public function getDriverSidePanelRequestNewItinerary($request, $response)
    {
        $last_itenerary = Itineraries::where('drivers_id', (int)$request->getParam('drivers_id'))
            ->select('id')
            ->orderBy('id', 'DESC')
            ->first();
        $legs = Legs::where('itineraries_id', (int)$last_itenerary->id)
            ->get();
        if ($legs->count() == 0) {
            $this->container->flash->addMessage('error', 'An empty itinerary has already been created');
            return $response->withRedirect($this->container->router->pathFor(
                'admin.map-view',
                ['department' => $request->getParam('department')],
                ['drivers_id' => $request->getParam('drivers_id')]
            ));
        } else {
            foreach ($legs as $leg) {
                if (!in_array($leg->status, ['completed', 'archived', 'dropped'])) {
                    $this->container->flash->addMessage('error', 'Job #' . $leg->jobs_id . ' must be completed or archived');
                    return $response->withRedirect($this->container->router->pathFor(
                        'admin.map-view',
                        ['department' => $request->getParam('department')],
                        ['drivers_id' => $request->getParam('drivers_id')]
                    ));
                }
            }
        }

        $itinerary = new Itineraries();
        $itinerary->drivers_id = (int)$request->getParam('drivers_id');
        if ($itinerary->save()) {
            $this->container->flash->addMessage('info', 'New itinerary created');
        } else {
            $this->container->flash->addMessage('error', 'Unknown error created itenerary');
        }
        return $response->withRedirect($this->container->router->pathFor(
            'admin.map-view',
            ['department' => $request->getParam('department')],
            ['drivers_id' => $request->getParam('drivers_id')]
        ));
    }

    public function postDispatchDriver($request, $response)
    {
        $response = $response->withAddedHeader('Content-Type', 'application/json');
        try {

            $this->container->truckhub->dispatcher->dispatch(
                (int)$request->getParam('drivers_id'),
                (int)$request->getParam('legs_id')
            );
            $type = Drivers::where("id", (int)$request->getParam('drivers_id'))->get();

            if ($type[0]->type == 'ltl') {
                $this->addRoutine($response, (int)$request->getParam('legs_id'), (int)$request->getParam('drivers_id'));
                $this->container->flash->addMessage('info', 'Leg dispatched to driver');
            } else {
                $this->container->flash->addMessage('info', 'Leg dispatched to driver');
            }

            $this->container->flash->addMessage('info', 'Leg dispatched to driver');
        } catch (Exception $e) {

            return $response->withJson(['status' => 'error', 'message' => $e->getMessage()]);
        }
        return $response->withJson(['status' => 'ok']);
    }


    public function postDispatchJobRoutine($request, $response)
    {
        $response = $response->withAddedHeader('Content-Type', 'application/json');
        try {

            $this->container->truckhub->dispatcher->dispatch(
                (int)$request->getParam('drivers_id'),
                (int)$request->getParam('legs_id')
            );
            $type = Drivers::where("id", (int)$request->getParam('drivers_id'))->get();

            if ($type[0]->type == 'ltl') {
                $this->addRoutineLeg($response, (int)$request->getParam('routines_id'), (int)$request->getParam('legs_id'));
                $this->container->flash->addMessage('info', 'Leg dispatched to driver');
            } else {
                $this->container->flash->addMessage('info', 'Leg dispatched to driver');
            }

            $this->container->flash->addMessage('info', 'Leg dispatched to driver');
        } catch (Exception $e) {

            return $response->withJson(['status' => 'error', 'message' => $e->getMessage()]);
        }
        return $response->withJson(['status' => 'ok']);
    }

    public function getUndispatchDriver($request, $response)
    {

        try {
            $this->container->truckhub->dispatcher->cancelDispatch(
                (int)$request->getParam('legs_id'),
                (int)$this->container->auth->user()->id
            );
            $this->container->flash->addMessage('info', 'Leg has been cancelled');
        } catch (Exception $e) {

            $this->container->flash->addMessage('error', 'Unable to cancel leg');
        }
        return $response->withRedirect($this->container->router->pathFor(
            'admin.map-view',
            ['department' => $request->getParam('department')],
            ['drivers_id' => $request->getParam('drivers_id')]
        ));
    }

    public function postJobPositionUpdate($request, $response)
    {
        $response = $response->withAddedHeader('Content-Type', 'application/json');
        $payload = json_decode($request->getBody());

        $assignment_history = new AssignmentHistory();
        $assignment_history->legs_id = $payload->target_legs_id;
        $assignment_history->users_id = $this->container->auth->user()->id;
        $assignment_history->drivers_id = $payload->drivers_id;
        $assignment_history->type = 'position_change';
        $assignment_history->department = $payload->department;
        $previousLegs = [];

        foreach ($payload->legs as $l) {

            $leg = Legs::with('origin.address', 'destination.address')->find((int)$l->id);
            $leg->position = (int)$l->position;
            $leg->save();

            // check if current leg is part of dispatched or active leg status list
            if (v::in(array_merge(LegStatus::getDispatchedStatusList(), LegStatus::getActiveStatusList()))->validate($leg->status)) {
                Metrics::updateOrCreate([
                    'type' => 'unloaded',
                    'legs_id' => $leg->id
                ], [
                    'miles' => (last($previousLegs) ? AlkMaps::calculateMileageZip(
                        last($previousLegs)->destination->address->zip,
                        $leg->origin->address->zip
                    ) : 0),
                    'minutes' => null,
                    'origins_id' => (last($previousLegs) ? last($previousLegs)->destination->id : $leg->origin->id),
                    'destinations_id' => $leg->origin->id
                ]);

                $leg = Legs::find((int)$l->id);

                $leg->position = (int)$l->position;

                if (!$leg->save()) {
                    return $response->withJson(['result' => 'error']);
                }
            }
        }
        if ($assignment_history->save()) {
            return $response->withJson(['result' => 'ok']);
        } else {
            return $response->withJson(['result' => 'error']);
        }
    }

    public function getBeacons($request, $response)
    {
        $user = $this->container->auth->user();
        $drivers = Drivers::where('type', $request->getParam('zone'))
            ->whereHas('user', function ($q) use ($user) {
                $q->where('group', $user->group);
            })
            ->whereHas('beacon', function ($q) {
                $q->whereNotNull('latitude')
                    ->whereNotNull('longitude');
            })
            ->with(
                'beacon:id,legs_id,drivers_id,latitude,longitude,speed,bearing,accuracy,updated_at',
                'user:id,name'
            )
            ->select('id', 'users_id')
            ->get();

        if ($request->getParam('delta_coordinates') === 'true') {
            foreach ($drivers as $driver) {
                $delta_coordinates = Beacons::getDeltaCoordinates((int)$driver->beacon->id);
                $driver->beacon->latitude = $delta_coordinates['latitude'];
                $driver->beacon->longitude = $delta_coordinates['longitude'];
            }
        }
        return $response->withJson($drivers);
    }

    public function postGetCurrentMarkerLocations($request, $response)
    {
        $result = [];
        if (!$request->getParam('jobs')) {
            return $response->withJson($result);
        }

        foreach ($request->getParam('jobs') as $job) {
            $leg = $this->container->db->table('legs')->select('status', 'id', 'jobs_id')->find($job['leg_id']);
            $coordinates = $this->container->db->table('addresses')
                ->select('latitude', 'longitude')
                ->find($job['address_id']);
            $result[] = [
                'status' => $leg->status,
                'leg_id' => $leg->id,
                'job_id' => $leg->jobs_id,
                'latitude' => $coordinates->latitude,
                'longitude' => $coordinates->longitude,
                'shipper_name' => $this->container->truckhub->job->getShippersName($leg->jobs_id),
                'icon' => $icon->icon_name ?? false
            ];
        }

        return $response->withJson($result);
    }

    public function getJobRouteWithDriver($request, $response, $arguments)
    {
        $job = Jobs::select('id')
            ->with(
                'legs:id,status,jobs_id',
                'legs.origin:id,legs_id,addresses_id',
                'legs.destination:id,legs_id,addresses_id',
                'legs.origin.address:id,latitude,longitude,name',
                'legs.destination.address:id,latitude,longitude,name'
            )
            ->where('id', $arguments['job_id'])
            ->first();
        if (!$job) {
            return $response->withJson(['error' => 'no route found']);
        }

        $job->current_leg = new \stdClass;
        $job->current_leg->id = $this->container->truckhub->job->getCurrentLegId($job->id);
        $job->current_leg->target = $this->container->truckhub->status->leg->isLegStatusOrigin(Legs::select('status')->find($job->current_leg->id)->status) ? 'origin' : 'destination';

        $result = [];
        $result['job_id'] = $job->id;
        $result['legs'] = [];

        $result['current_leg_id'] = $job->current_leg->id;
        $result['current_leg_target'] = $job->current_leg->target;

        foreach ($job->legs as $leg) {
            $result['legs'][] = [
                'leg_id' => $leg->id,
                'leg_status' => $leg->status,
                'origin' => [
                    'lat' => $leg->origin->address->latitude,
                    'lng' => $leg->origin->address->longitude,
                    'name' => $leg->origin->address->name
                ],
                'destination' => [
                    'lat' => $leg->destination->address->latitude,
                    'lng' => $leg->destination->address->longitude,
                    'name' => $leg->destination->address->name
                ]
            ];
        }


        //$driver_id = $this->container->truckhub->job->getOptimalDriverId($job->id);
        //$driver_id = 3;


        $driver_id = $this->container->truckhub->job->getOptimalDriverId($job->id);


        $beacon = Beacons::select('id', 'drivers_id', 'latitude', 'longitude')
            ->with(
                'driver:id,users_id',
                'driver.user:id,name'
            )
            ->where('drivers_id', 4)
            ->first();

        $result['driver'] = [
            'driver_id' => $beacon->driver->id,
            'name' => $beacon->driver->user->name,
            'lat' => $beacon->latitude,
            'lng' => $beacon->longitude,
            'time_to_target' => '2 hrs 30 min'
        ];


        return $response->withJson($result);
    }


    /* New For Routines*/

    //Add a new routine
    public function addRoutine($response, $leg_id, $driver_id)
    {


        $last_routine = Routines::where('drivers_id', $driver_id)->orderBy("position", 'DESC')->first();
        $position = 0;
        if ($last_routine) {

            $position = $last_routine->position + 1;
        }
        $routine = new Routines([
            'drivers_id' => $driver_id,
            'position' => $position,
            'itineraries_id' => $driver_id,
        ]);

        if ($routine->save()) {

            $jobs_leg = Legs::where('id', $leg_id)->get();
            $jobs = Jobs::where('id', $jobs_leg[0]->jobs_id)
                ->with(
                    'legs',
                    'origins',
                    'origins.address',
                    'destinations',
                    'destinations.address'
                )->get()->toArray();

            RoutineLegs::create([
                'routines_id' => $routine->id,
                'point_a_type' => Origins::class,
                'point_a_id' => $jobs[0]['origins'][0]['id'],
                'point_b_type' => Destinations::class,
                'point_b_id' => $jobs[0]['destinations'][0]["id"],

            ])->save();
            //$this->container->flash->addMessage('info', 'Routine has successfully been created');
            return $response->withJson($routine, 200);
        }
    }

    // Add a new routine leg
    public function addRoutineLeg($response, $routines_id, $legs_id)
    {
        $routine = Routines::where('id', $routines_id)->first();;
        if ($routine) {
            $jobs_leg = Legs::where('id', $legs_id)->first();
            $jobs = Jobs::where('id', $jobs_leg->jobs_id)
                ->with(
                    'legs',
                    'origins',
                    'origins.address',
                    'destinations',
                    'destinations.address'
                )->get()->toArray();

            $routine_leg = RoutineLegs::create([
                'routines_id' => $routine->id,
                'point_a_type' => Origins::class,
                'point_a_id' => $jobs[0]['origins'][0]['id'],
                'point_b_type' => Destinations::class,
                'point_b_id' => $jobs[0]['destinations'][0]["id"],

            ])->save();
            return $response->withJson($routine_leg, 200);
        }
    }


    private function getRoutineLegs($legs)
    {
        $legs_arr = [];
        foreach ($legs as $leg) {
            $legs_arr[] = $leg->id;
        }
        return $legs_arr;
    }

    private function getRoutineLegsId($legsx)
    {
        $legs_arrx = [];
        foreach ($legsx as $legx) {
            $legs_arrx[] = $legx['routine_id'];
        }
        return $legs_arrx;
    }

    private function getLegsId($legsz)
    {
        $legs_arrz = [];
        foreach ($legsz as $legz) {
            $legs_arrz[] = $legz['leg_id'];
        }
        return $legs_arrz;
    }


    protected function getRoutines($driver_id)
    {
        $routines = Routines::where('drivers_id', $driver_id)->orderByraw('position', 'desc')->get();
        return ['routines' => $routines];
        /*$routine = Routines::where('drivers_id', $driver_id)->orderBy('position', 'DESC')->get()->toArray();
        $arr3 = [];

        foreach ($routine as $i) {

            $routines_legs = RoutineLegs::where('routines_id', $i['id'])->get()->toArray();

            foreach ($routines_legs as $routines_leg) {

                if ($routines_leg['point_a_type'] == Origins::class) {
                    $jobs_leg_a = Origins::where('id', $routines_leg['point_a_id'])->get()->toArray();
                } else {
                    $jobs_leg_a = Destinations::where('id', $routines_leg['point_a_id'])->get()->toArray();
                }

                if ($routines_leg['point_b_type'] == Origins::class) {
                    $jobs_leg_b = Origins::where('id', $routines_leg['point_b_id'])->get()->toArray();
                } else {
                    $jobs_leg_b = Destinations::where('id', $routines_leg['point_b_id'])->get()->toArray();
                }

            }

            $jobs = Jobs::where('id', $jobs_leg_a[0]['jobs_id'])
                ->with(
                    'legs',
                    'ltl',
                    'origins',
                    'origins.address',
                    'destinations',
                    'destinations.address'

                )->get()->toArray();

            $arr3[] = ['routine' => Routines::where('id', $i['id'])->get()->toArray(), 'legs' => RoutineLegs::where('routines_id', $i['id'])->get()->toArray(), 'jobs' => $jobs];
            dump($arr3);die();
        }
        return $arr3;*/

    }

    /*private function deleteRoutineLegs($leg_id)
    {

        $routine_id = RoutineLegs::select("routine_id")->where('leg_id', $leg_id)->get();
        $routine = RoutineLegs::where('routine_id', $routine_id[0]->routine_id)->get()->toArray();
        $routine_leg = RoutineLegs::where('leg_id', $leg_id)->delete();
        RoutineItinerary::where('leg_id', $leg_id)->delete();

        if (count($routine) == 1) {
            LtlRoutine::where('id', $routine[0]['routine_id'])->delete();
        }
        return $routine_leg;
    }*/

    private function removeRoutineLegs($legs3)
    {
        $legs_arr2 = array_unique(RoutineLegs::whereIn('leg_id', $this->getRoutineLegs($legs3))->get(['routine_id'])->toArray(), SORT_REGULAR);
        foreach ($legs_arr2 as $i) {
            foreach ($legs3 as $key => $val) {
                $routines = RoutineLegs::where('routine_id', $i['routine_id'])->get();
                foreach ($routines as $r) {
                    if ($val->id === $r->leg_id) {
                        unset($legs3[$key]);
                    }
                }
            }
        }
        return $legs3;
    }


    public function getExportItineraryPDF($request, $response, $arguments)
    {
        $itinerary_id = (int)$arguments['itinerary_id'];
        $driver_id = $this->container->db->table('itineraries')
            ->select('drivers_id')
            ->where('id', $itinerary_id)
            ->first()
            ->drivers_id;
        $driver = Drivers::where('id', $driver_id)
            ->with('user')
            ->first();
        $legs = Legs::where('itineraries_id', $itinerary_id)
            ->with([
                'origin.address',
                'metric_loaded',
                'metric_unloaded'
            ])
            ->orderBy('position', 'ASC')
            ->get();
        $data = [];
        $data['legs'] = $legs;
        $data['driver'] = $driver;
        $data['itinerary_id'] = $itinerary_id;
        $template = $this->container->view->fetch('admin/pdf/itinerary.mileage.report.twig', $data);
        $dompdf = new Dompdf();
        $dompdf->loadHtml($template);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream('mileage-report.pdf', array("Attachment" => true));
    }


    public function postJobPositionUpdateNewDispatch($request, $response)
    {
        $jobs_legs_order1 = JobsLegsOrder::where('driver_id', $request->getParam('drivers_id'))->get();

        if (count($jobs_legs_order1->toArray()) > 0) {
            $result = json_decode($jobs_legs_order1[0]->jobs);
            $new_leg = ['l-' . $request->getParam('legs_id')];
            $final = array_merge($new_leg, $result);

            if ($jobs_legs_order1[0]->update(['jobs' => json_encode($final)])) {
                return $response->withJson($jobs_legs_order1, 200);
            } else {
                return $response->withJson($jobs_legs_order1, 500);
            }
        }
    }


    public function postJobPositionUpdateNew($request, $response)
    {

        $response = $response->withAddedHeader('Content-Type', 'application/json');
        $payload = json_decode($request->getBody());

        $routines_order = Routines::where('drivers_id', $payload->driver_id)->get()->toArray();


        if (count($routines_order) > 0) {
            $cont = count($routines_order) - 1;
            foreach ($payload->jobs as $routine) {
                Routines::where("id", $routine)->update(['position' => $cont]);
                $cont--;
            }
            return $response->withJson("OK", 200);
        }
    }


    private function getAllJobsOrdered($driver_id, $routines, $legs, $previous_legs)
    {


        $result = [];
        $obj = JobsLegsOrder::where('driver_id', $driver_id)->get();

        if (count($obj) > 0) {
            $tmp = [];
            $list_ordered = count(json_decode($obj[0]->jobs)) > 0 ? json_decode($obj[0]->jobs) : [];
            foreach ($routines as $r) {
                $tmp[] = ['r-' . $r['routine'][0]->id => $r];
            }
            foreach ($legs as $l) {
                $tmp[] = ['l-' . $l->id => $l];

            }
            foreach ($previous_legs as $pl) {
                $tmp[] = ['i-' . $pl->id => $pl];
            }
            $merged = array_merge(...$tmp);
            foreach ($list_ordered as $key) {
                if (array_key_exists($key, $merged)) {
                    $result[] = [$key => $merged[$key]];
                }
            }
        }
        if (count($result) > 0) {
            return array_merge(...$result);
        } else {
            return $result;
        }
    }

}