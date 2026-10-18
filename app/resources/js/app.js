// The landing device is the only thing on the site that needs scripting, and the trader app never has
// one on the page. Importing it on sight keeps the bundle honest about what ships where.
if (document.querySelector('[data-send-demo]')) {
    import('./landing-demo').then((module) => {
        module.initSendDemo(document.querySelector('[data-send-demo]'));
    });
}