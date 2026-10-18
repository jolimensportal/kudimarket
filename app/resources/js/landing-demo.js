const STATES = ['amount', 'rate', 'receiver', 'confirm', 'sent'];

const money = (value) => value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function initSendDemo(root) {
    const rate = Number(root.dataset.rate);
    const panels = Array.from(root.querySelectorAll('[data-screen]'));
    const steps = Array.from(root.querySelectorAll('.device__steps li'));
    const chips = Array.from(root.querySelectorAll('.device__chip'));

    let amount = Number(chips.find((chip) => chip.classList.contains('is-on')).dataset.amount);

    const paint = () => {
        root.querySelectorAll('[data-bind="send"]').forEach((node) => { node.textContent = '₵' + money(amount); });
        root.querySelectorAll('[data-bind="receive"]').forEach((node) => { node.textContent = '₦' + money(amount * rate); });
    };

    const current = () => panels.find((panel) => !panel.hidden)?.dataset.screen;

    const show = (name) => {
        const index = STATES.indexOf(name);
        if (index < 0) { return; }

        panels.forEach((panel) => { panel.hidden = panel.dataset.screen !== name; });
        steps.forEach((step, position) => { step.classList.toggle('on', position <= index); });
    };

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            amount = Number(chip.dataset.amount);
            chips.forEach((other) => {
                other.classList.toggle('is-on', other === chip);
                other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
            });
            paint();
        });
    });

    root.querySelectorAll('[data-go]').forEach((button) => {
        button.addEventListener('click', () => show(button.dataset.go));
    });

    root.querySelectorAll('[data-back]').forEach((button) => {
        button.addEventListener('click', () => show(STATES[Math.max(0, STATES.indexOf(current()) - 1)]));
    });

    root.querySelector('[data-restart]').addEventListener('click', () => show('amount'));

    root.querySelector('[data-waitlist]').addEventListener('click', () => {
        const field = document.querySelector('#email');
        if (!field) { return; }
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        field.focus({ preventScroll: true });
    });

    paint();
    show('amount');
}