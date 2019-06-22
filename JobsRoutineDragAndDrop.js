export class JobsRoutineDragAndDrop {

    constructor() {
        this.legs = document.querySelectorAll('#job-entries .jobs-entry .jobs-leg');
        this.routines = document.querySelectorAll('li.routine-block');
        this.setUpListeners();
    }

    setUpListeners() {
        for (let i = 0; i < this.routines.length; i++) {
            this.setUpTarget(this.routines[i]);
        }

        for (let i = 0; i < this.legs.length; i++) {
            this.setUpSubject(this.legs[i]);
        }
    }

    setUpTarget(e) {
        e.addEventListener('dragover', this.dragOver, false);
        e.addEventListener('dragleave', this.dragLeave, false);
        e.addEventListener('drop', this.drop, false);
    }

    setUpSubject(e) {
        if (e.getAttribute('draggable') == 'false') {
            return;
        }
        e.setAttribute('draggable', 'true');
        e.addEventListener('dragstart', this.dragStart, false);
        e.addEventListener('dragend', this.dragEnd, false);
    }

    drop(e) {
        e.preventDefault();
        this.classList.remove('drag_over_target');

        let formData = new FormData(),
            drivers_id = e.currentTarget.getAttribute('drivers-id'),
            routines_id = e.currentTarget.getAttribute('routine-id');

        formData.append('legs_id', e.dataTransfer.getData('text'));
        formData.append('drivers_id', drivers_id);
        formData.append('routines_id', routines_id);

        let loader = document.createElement("div");
        loader.classList.add('full-page-loader');
        document.querySelector('body').appendChild(loader);

        fetch('dispatch-job-routine', {
            method: 'POST',
            body: formData
        }).then(response => {
            return response.json();
        }).then(data => {
            if (data.status == 'ok') {
                window.location = `?drivers_id=${drivers_id}`;
            } else {
                alert('Error dispatching');
            }
        }).catch(() => alert('Error reading response'));
    }

    dragOver(e) {
        e.preventDefault();
        this.classList.add('drag_over_target');
    }

    dragLeave(e) {
        this.classList.remove('drag_over_target');
    }

    dragStart(e) {
        this.classList.add('subject_dragged');
        e.dataTransfer.setData('text', e.currentTarget.getAttribute('legs-id'));
    }

    dragEnd(e) {
        this.classList.remove('subject_dragged');
    }
}