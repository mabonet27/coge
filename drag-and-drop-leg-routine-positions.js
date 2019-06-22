var source,
    map_api,
    drivers_id;

export function setDragAndDropRoutinesPositions(driversId) {
    map_api = window.mapView.map;
    drivers_id = driversId;
    addListListener();
}

function addListListener() {

    let cols = []; //document.querySelectorAll('#itinerary-list .routine-block');

        [].forEach.call(cols, addDnDHandlerss);
}

function orderPositions() {
    let routine_id = [];
    let entries = document.querySelectorAll('#itinerary-list > li');

    for (let i = 0, x = entries.length - 1; i < entries.length; i++, x--) {
        entries[i].setAttribute('position', x);
    }

   return routine_id;
}


export function jobsList() {
    let jobs = [];
    let entries = document.querySelectorAll('#itinerary-list > li');
    entries.forEach(function (entry) {
        if (entry.getAttribute('routine-id') != -1) {
            jobs.push(entry.getAttribute('routine-id'));
        }
    });
    return jobs;
}

function highLight(result) {

    let status = result === 'ok' ? 'success' : 'error';
    source.classList.add(status);
    setTimeout(() => {
        source.classList.remove(status);
    }, 400);
}

function postAjaxUpdate(payload) {
    postJobPositionUpdateAjax('/admin/map-view/post-job-position-update', payload).then(data => {
        highLight(data.result);
    });
}

function postJobPositionUpdateAjax(url = '', data = {}) {
    return fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(data)
    })
        .then(response => {
            return response.text();
        });
}

export function postJobPositionUpdateAjaxNew(data = {}) {
    let url = '/admin/map-view/post-job-position-update-new';
    return fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(data)
    })
        .then(response => {
            return response.text();
        });
}

function isbefore(a, b) {

    if (a.parentNode == b.parentNode) {
        for (var cur = a; cur; cur = cur.previousSibling) {
            if (cur === b) {
                return true;
            }
        }
    }
    return false;
}

function dragenter(e) {

    if (e.currentTarget !== source) {
        this.classList.add('over');
    }

    if (isbefore(source, e.currentTarget)) {
        e.currentTarget.parentNode.insertBefore(source, e.currentTarget);
    } else {
        e.currentTarget.parentNode.insertBefore(source, e.currentTarget.nextSibling);
    }
}

function dragstart(e) {
    source = e.currentTarget;
    e.dataTransfer.effectAllowed = 'move';
    this.classList.add('moving');
}

function dragover(e) {
    if (e.preventDefault) {
        e.preventDefault();
    }
    e.dataTransfer.dropEffect = 'move';
    return false;
};

function dragleave(e) {
    this.classList.remove('over');
};

function drop(e) {
    let entries = document.querySelectorAll('.itinerary-entry-header.pending_ltl');

    for (let i = 0; i < entries.length; i++) {
        entries[i].parentElement.classList.remove('over', 'moving');
    }
}

function dragend(e) {

     orderPositions();

    //to save the order between legs and routines
    let jobs_legs_order = {};
    jobs_legs_order.driver_id = drivers_id;
    jobs_legs_order.jobs = jobsList();
     postJobPositionUpdateAjaxNew(jobs_legs_order);

    /*let entries = document.querySelectorAll('.itinerary-entry-header.pending'),
        job_priority = {};
    job_priority.drivers_id = drivers_id;
    job_priority.legs = [];
     //check if its a routine or leg
    if(e.currentTarget.getAttribute('legs-id')){
        job_priority.target_legs_id = e.currentTarget.getAttribute('legs-id');
        job_priority.department = window.mapView.department_context;
    }
    else {
        job_priority.target_legs_id = legs_id[0];
        job_priority.department = window.mapView.department_context;

    }
    //put position 0 to legs into a routine
    for (let j = 0; j < legs_id.length; j++) {
        job_priority.legs.push({
            id: legs_id[j],
            position: 0
        });
    }

    for (let i = 0; i < entries.length; i++) {
        if (entries[i].parentElement.getAttribute('legs-id')) {
            job_priority.legs.push({
                id: entries[i].parentElement.getAttribute('legs-id'),
                position: entries[i].parentElement.getAttribute('position')
            });
        }
    }*/
}

    function addDnDHandlerss(elem) {


        elem.addEventListener('dragstart', dragstart, false);
        elem.addEventListener('dragenter', dragenter, false)
        elem.addEventListener('dragover', dragover, false);
        elem.addEventListener('dragleave', dragleave, false);
        elem.addEventListener('drop', drop, false);
        elem.addEventListener('dragend', dragend, false);


   // postAjaxUpdate(job_priority);



};
