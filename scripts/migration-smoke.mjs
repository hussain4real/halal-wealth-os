import {
    migrationSmoke,
    reportFailure,
    testingEnvironment,
} from './verification.mjs';

try {
    migrationSmoke(testingEnvironment());
} catch (error) {
    reportFailure(error);
}
