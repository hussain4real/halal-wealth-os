export function verificationMode(args) {
    if (args.length === 1 && args[0] === '--all')
        return { all: true, phase: null, prelive: false };
    if (args.length === 2 && args[0] === '--phase')
        return { all: false, phase: args[1], prelive: false };
    if (args.length === 2 && args[0] === '--prelive' && args[1] === 'M7-P3')
        return { all: false, phase: 'M7-P3', prelive: true };
    throw new Error(
        'Usage: ./bin/verify --phase Mx-Py | --all | --prelive M7-P3',
    );
}
