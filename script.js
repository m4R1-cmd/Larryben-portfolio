// ===== Typing effect on the home page =====

var typed = document.getElementById('typed');

if (typed) {

    var roles = [
        'Information Systems Instructor',
        'IT Support Specialist',
        'Freelance Computer Technician'
    ];

    var r = 0;
    var i = 0;
    var deleting = false;

    (function tick() {

        var word = roles[r];

        i += deleting ? -1 : 1;

        typed.textContent = word.slice(0, i);

        var delay = deleting ? 35 : 80;

        if (!deleting && i === word.length) {

            deleting = true;
            delay = 1600;

        } else if (deleting && i === 0) {

            deleting = false;
            r = (r + 1) % roles.length;
            delay = 400;

        }

        setTimeout(tick, delay);

    })();
}


// ===== Hide social icons that don't have a real link =====

Array.prototype.forEach.call(
    document.querySelectorAll('.social a[href="#"]'),
    function (a) {
        a.hidden = true;
    }
);


// ===== Certificates: loaded from certs.json =====

var certBox = document.getElementById('cert-list');


// ===== Validate certificate file path =====

function safePath(p) {

    return typeof p === 'string' &&
        /^certs\/[A-Za-z0-9._-]+$/.test(p);

}


// ===== Show certificate message =====

function showMessage(text) {

    if (!certBox) return;

    certBox.textContent = '';

    var p = document.createElement('p');

    p.className = 'note';
    p.textContent = text;

    certBox.appendChild(p);

}


// ===== Display certificates =====

function showCerts(items) {

    if (!certBox) return;

    if (!Array.isArray(items) || !items.length) {

        showMessage('No certificates yet.');

        return;
    }

    certBox.textContent = '';

    items.forEach(function (c) {

        // Validate certificate paths

        if (
            !safePath(c.file) ||
            (c.thumb && !safePath(c.thumb))
        ) {

            console.warn(
                'Certificate skipped because the file path is invalid:',
                c.file
            );

            return;
        }


        // Create certificate card

        var card = document.createElement('div');

        card.className = 'cert-card';


        // Create certificate link

        var a = document.createElement('a');

        a.href = c.file;
        a.target = '_blank';
        a.rel = 'noopener';


        // PDF certificate

        if (c.type === 'pdf' && !c.thumb) {

            var pdf = document.createElement('div');

            pdf.className = 'pdf';
            pdf.textContent = '📄';

            a.appendChild(pdf);

        }


        // Image certificate

        else {

            var img = document.createElement('img');

            img.src = c.thumb || c.file;
            img.alt = c.title || 'Certificate';
            img.loading = 'lazy';

            a.appendChild(img);

        }


        // Certificate information

        var meta = document.createElement('div');

        meta.className = 'meta';


        var b = document.createElement('b');

        b.textContent = c.title || 'Certificate';


        var s = document.createElement('span');

        s.textContent = [c.issuer, c.year]
            .filter(Boolean)
            .join(' · ');


        meta.appendChild(b);
        meta.appendChild(s);

        a.appendChild(meta);

        card.appendChild(a);

        certBox.appendChild(card);

    });

}


// ===== Load certificates from certs.json =====

if (certBox) {

    fetch('certs.json?v=5')

        .then(function (res) {

            if (!res.ok) {

                throw new Error(
                    'HTTP ' + res.status
                );

            }

            return res.json();

        })

        .then(showCerts)

        .catch(function (error) {

            console.error(
                'Certificate loading error:',
                error
            );

            showMessage(
                'Certificates could not be loaded right now.'
            );

        });

}
