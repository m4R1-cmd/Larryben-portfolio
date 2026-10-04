// ===== Certificates: loaded from certs.json =====

var certBox = document.getElementById('cert-list');

// Only allow files that live inside the certs/ folder
function safePath(p) {
    return typeof p === 'string' &&
        /^certs\/[A-Za-z0-9._-]+$/.test(p);
}

function showMessage(text) {
    if (!certBox) return;

    certBox.textContent = '';

    var p = document.createElement('p');
    p.className = 'note';
    p.textContent = text;

    certBox.appendChild(p);
}

function showCerts(items) {
    if (!certBox) return;

    if (!Array.isArray(items) || !items.length) {
        showMessage('No certificates yet.');
        return;
    }

    certBox.textContent = '';

    items.forEach(function (c) {

        if (!safePath(c.file) || (c.thumb && !safePath(c.thumb))) {
            console.warn(
                'Certificate skipped because the file path is invalid:',
                c.file
            );
            return;
        }

        var card = document.createElement('div');
        card.className = 'cert-card';

        var a = document.createElement('a');
        a.href = c.file;
        a.target = '_blank';
        a.rel = 'noopener';

        if (c.type === 'pdf' && !c.thumb) {

            var pdf = document.createElement('div');
            pdf.className = 'pdf';
            pdf.textContent = '📄';

            a.appendChild(pdf);

        } else {

            var img = document.createElement('img');

            img.src = c.thumb || c.file;
            img.alt = c.title || 'Certificate';
            img.loading = 'lazy';

            a.appendChild(img);
        }

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

if (certBox) {

    fetch('certs.json?v=3')
        .then(function (res) {

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            return res.json();
        })
        .then(showCerts)
        .catch(function (error) {

            console.error('Certificate loading error:', error);

            showMessage(
                'Cert\ificates could not be loaded right now.'
            );
        });
}