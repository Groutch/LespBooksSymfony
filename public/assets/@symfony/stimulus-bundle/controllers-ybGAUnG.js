import controller_0 from "../ux-turbo/turbo_controller.js";
import "../ux-turbo/mercure_stream_source_element.js";
import controller_1 from "../../controllers/barcode_scanner_controller.js";
import controller_2 from "../../controllers/hello_controller.js";
import controller_3 from "../../controllers/isbn_autofill_controller.js";
export const eagerControllers = {"symfony--ux-turbo--turbo-core": controller_0, "barcode-scanner": controller_1, "hello": controller_2, "isbn-autofill": controller_3};
export const lazyControllers = {"csrf-protection": () => import("../../controllers/csrf_protection_controller.js")};
export const isApplicationDebug = false;