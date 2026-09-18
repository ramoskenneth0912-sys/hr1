import { Router } from 'express';
import { stats } from '../controllers/admin.controller.js';
import { index as goalsIndex, show as goalsShow } from '../controllers/goals.controller.js';
import { index as recognitionIndex, show as recognitionShow } from '../controllers/recognition.controller.js';
import { options as performanceOptions } from '../controllers/performance.controller.js';
import { index as performanceReviewsIndex } from '../controllers/performance-reviews.controller.js';

export const adminRouter = Router();

adminRouter.get('/stats', stats);
adminRouter.get('/goals', goalsIndex);
adminRouter.get('/goals/:id', goalsShow);
adminRouter.get('/recognition', recognitionIndex);
adminRouter.get('/recognition/:id', recognitionShow);
adminRouter.get('/performance/options', performanceOptions);
adminRouter.get('/performance/reviews', performanceReviewsIndex);

export default adminRouter;
