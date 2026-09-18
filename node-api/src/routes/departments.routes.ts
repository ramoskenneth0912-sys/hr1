import { Router } from 'express';
import { index } from '../controllers/departments.controller.js';

export const departmentsRouter = Router();

departmentsRouter.get('/', index);

export default departmentsRouter;